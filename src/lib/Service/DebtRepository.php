<?php

declare(strict_types=1);

namespace USDebt\Service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use USDebt\Exception\DataUnavailableException;
use USDebt\Model\DebtDataset;
use USDebt\Model\DebtEntry;

/**
 * Daily cache of the FULL Treasury debt history.
 *
 * - The whole dataset is downloaded at most once per day (America/New_York date)
 *   and saved as one JSON file. Every page and every Compare president is sliced
 *   from that one copy, so visitors never trigger extra Treasury calls.
 * - A lock makes sure only one request downloads. The others keep serving the
 *   last saved copy as normal data, or, when there is no copy yet, wait a bounded
 *   time for the download to finish.
 * - A download happens ONLY when the result can be saved and the lock is held.
 *   If the cache folder is not writable or the lock cannot be opened, nothing is
 *   downloaded: the saved copy is served (marked stale) or the visitor gets the
 *   friendly error. That way a broken cache folder can never turn into one
 *   Treasury download per page view.
 * - The file is written to a temp name and renamed, so readers never see half a file.
 *   Folder 0750, files 0640: no access for other users on the server.
 * - If the download fails or is incomplete, nothing is saved: the last good copy is
 *   served marked stale and the download is not retried for RETRY_AFTER_SECONDS,
 *   so a Treasury outage does not make every page wait for the timeout.
 * - If the download works but cannot be saved (disk full, rename fails), this
 *   request still shows the new data, and the same back-off starts, so the next
 *   requests do not download again.
 * - If the download fails and there is no copy, DataUnavailableException is thrown.
 * - Lasting problems (broken cache folder or lock) are logged once per back-off
 *   window, not once per page view.
 */
final class DebtRepository implements DebtSource
{
    public const CACHE_FILE = 'treasury-debt.json';
    public const LOCK_FILE = 'treasury-debt.lock';
    public const FAIL_FILE = 'treasury-debt.failed';
    public const RETRY_AFTER_SECONDS = 900;
    /** Longest time a request with no saved copy waits for another request's download. */
    public const LOCK_WAIT_SECONDS = 25.0;
    public const DIR_MODE = 0750;
    public const FILE_MODE = 0640;
    private const FORMAT_VERSION = 1;
    private const LOCK_POLL_SECONDS = 0.1;

    /** @var array<string, int> When this PHP process last logged each problem (Unix time). */
    private static array $logged = [];

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(float): void */
    private Closure $sleep;

    private string $logMarkerDir;

    /**
     * @param string                              $cacheDir        Folder for the cache file. Created if missing. Must not be web-served.
     * @param string|null                         $seedFile        JSON rows for days before the API's first day (see Data/debt_seed_1993.json).
     * @param (Closure(): DateTimeImmutable)|null $clock           "Now"; tests pass a fixed time.
     * @param float                               $lockWaitSeconds Longest wait for another request's download when there is no saved copy.
     * @param (Closure(float): void)|null         $sleep           Pause between lock checks; tests pass their own.
     * @param string|null                         $logMarkerDir    Where the "already logged" markers go (default: the system temp
     *                                                             folder, because the cache folder may be the thing that is broken).
     */
    public function __construct(
        private readonly FiscalDataClient $client,
        private readonly string $cacheDir,
        private readonly ?string $seedFile = null,
        ?Closure $clock = null,
        private readonly int $retryAfterSeconds = self::RETRY_AFTER_SECONDS,
        private readonly float $lockWaitSeconds = self::LOCK_WAIT_SECONDS,
        ?Closure $sleep = null,
        ?string $logMarkerDir = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
        $this->logMarkerDir = $logMarkerDir ?? sys_get_temp_dir();
    }

    public function load(): DebtDataset
    {
        $today = $this->today();
        $cached = $this->readCache();
        if ($cached !== null && $cached->savedOn() === $today) {
            return $cached;
        }

        if (!$this->ensureCacheDir()) {
            $this->logOnce("UsDebt: cache folder {$this->cacheDir} is missing or not writable; not downloading until it is fixed.");
            return $this->savedCopyOrFail($cached);
        }

        if ($this->failedRecently()) {
            return $this->savedCopyOrFail($cached);
        }

        $lockFile = $this->path(self::LOCK_FILE);
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            $this->logOnce("UsDebt: could not open the cache lock file {$lockFile}; not downloading until it is fixed.");
            return $this->savedCopyOrFail($cached);
        }
        @chmod($lockFile, self::FILE_MODE);
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                // Another request is downloading right now.
                if ($cached !== null) {
                    // A normal daily refresh is in progress and nothing has failed,
                    // so the saved copy is served as normal data (not flagged stale).
                    return $cached;
                }
                return $this->waitForOtherDownload($lock);
            }
            // We hold the lock. Another request may have finished the download while we waited.
            $cached = $this->readCache() ?? $cached;
            if ($cached !== null && $cached->savedOn() === $today) {
                return $cached;
            }
            return $this->downloadOrFallback($cached, $today);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * No saved copy and another request holds the lock: check again every
     * LOCK_POLL_SECONDS, for at most lockWaitSeconds, then serve what it saved.
     *
     * @param resource $lock
     */
    private function waitForOtherDownload($lock): DebtDataset
    {
        $tries = max(1, (int) ceil($this->lockWaitSeconds / self::LOCK_POLL_SECONDS));
        for ($i = 0; $i < $tries; $i++) {
            ($this->sleep)(self::LOCK_POLL_SECONDS);
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                continue;
            }
            $fresh = $this->readCache();
            if ($fresh !== null) {
                return $fresh;
            }
            // The other download failed and saved nothing; its failure marker starts the back-off.
            if ($this->failedRecently()) {
                throw new DataUnavailableException();
            }
            return $this->downloadOrFallback(null, $this->today());
        }
        error_log('UsDebt: gave up waiting for another request to finish the Treasury download.');
        throw new DataUnavailableException();
    }

    private function downloadOrFallback(?DebtDataset $cached, string $today): DebtDataset
    {
        try {
            $result = $this->client->fetchAll();
            if ($result['entries'] === []) {
                throw new \UnexpectedValueException('Treasury returned no usable rows');
            }
        } catch (Throwable $e) {
            error_log('UsDebt: Treasury download failed; nothing saved: ' . get_class($e) . ': ' . $e->getMessage());
            $this->markFailure();
            return $this->savedCopyOrFail($cached, $e);
        }

        if ($result['skipped'] > 0) {
            error_log("UsDebt: Treasury download skipped {$result['skipped']} bad row(s).");
        }
        $entries = $this->withSeed($result['entries']);
        if (!$this->writeCache($entries, $today, $result['skipped'])) {
            // The download worked but could not be saved (disk full, rename failed).
            // Start the back-off so the next requests do not download again.
            $this->markFailure();
            return new DebtDataset($entries, $today);
        }
        $this->clearFailure();
        return new DebtDataset($entries, $today);
    }

    /**
     * Used when no new data can be loaded: the saved copy marked stale, or the friendly error.
     */
    private function savedCopyOrFail(?DebtDataset $cached, ?Throwable $previous = null): DebtDataset
    {
        if ($cached === null) {
            throw new DataUnavailableException($previous);
        }
        return new DebtDataset($cached->all(), $cached->savedOn(), true);
    }

    /**
     * Add the seed rows for days before the first downloaded day.
     *
     * @param list<DebtEntry> $entries
     * @return list<DebtEntry>
     */
    private function withSeed(array $entries): array
    {
        if ($this->seedFile === null || $entries === []) {
            return $entries;
        }
        $first = $entries[0]->effectiveDate;
        $seed = [];
        foreach ($this->readRows($this->seedFile) as $entry) {
            if ($entry->effectiveDate < $first) {
                $seed[] = $entry;
            }
        }
        return array_merge($seed, $entries);
    }

    private function readCache(): ?DebtDataset
    {
        $file = $this->path(self::CACHE_FILE);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json) || ($json['version'] ?? null) !== self::FORMAT_VERSION
            || !is_string($json['saved_on'] ?? null) || !is_array($json['rows'] ?? null)) {
            error_log("UsDebt: cache file {$file} is unreadable; ignoring it.");
            return null;
        }
        $entries = $this->rowsToEntries($json['rows']);
        if ($entries === []) {
            return null;
        }
        return new DebtDataset($entries, $json['saved_on']);
    }

    /**
     * @param list<DebtEntry> $entries
     * @return bool  False when nothing could be saved.
     */
    private function writeCache(array $entries, string $today, int $skipped): bool
    {
        $rows = [];
        foreach ($entries as $e) {
            $rows[] = [$e->effectiveDate, $e->totalDebt];
        }
        $payload = json_encode([
            'version' => self::FORMAT_VERSION,
            'saved_on' => $today,
            'saved_at' => ($this->clock)()->format(DATE_ATOM),
            'source' => FiscalDataClient::ENDPOINT,
            'skipped_rows' => $skipped,
            'rows' => $rows,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($payload === false) {
            error_log('UsDebt: could not encode the cache file.');
            return false;
        }
        $target = $this->path(self::CACHE_FILE);
        $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false
            || !@chmod($tmp, self::FILE_MODE)
            || !@rename($tmp, $target)) {
            @unlink($tmp);
            error_log("UsDebt: could not write the cache file {$target}; not downloading again for {$this->retryAfterSeconds} seconds.");
            return false;
        }
        return true;
    }

    /**
     * @return list<DebtEntry>
     */
    private function readRows(string $file): array
    {
        $raw = @file_get_contents($file);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json) || !is_array($json['rows'] ?? null)) {
            error_log("UsDebt: seed file {$file} is unreadable; ignoring it.");
            return [];
        }
        return $this->rowsToEntries($json['rows']);
    }

    /**
     * Rows are [date, amount] pairs. Bad rows are skipped and counted, never fatal.
     *
     * @param array<mixed> $rows
     * @return list<DebtEntry>
     */
    private function rowsToEntries(array $rows): array
    {
        $entries = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $entry = is_array($row) && count($row) === 2
                ? FiscalDataClient::mapRow(['record_date' => $row[0] ?? null, 'tot_pub_debt_out_amt' => $row[1] ?? null])
                : null;
            if ($entry === null) {
                $skipped++;
                continue;
            }
            $entries[$entry->effectiveDate] = $entry;
        }
        if ($skipped > 0) {
            error_log("UsDebt: skipped {$skipped} bad saved row(s).");
        }
        ksort($entries, SORT_STRING);
        return array_values($entries);
    }

    private function failedRecently(): bool
    {
        $raw = @file_get_contents($this->path(self::FAIL_FILE));
        if (!is_string($raw) || !ctype_digit(trim($raw))) {
            return false;
        }
        return ($this->now() - (int) trim($raw)) < $this->retryAfterSeconds;
    }

    private function markFailure(): void
    {
        $file = $this->path(self::FAIL_FILE);
        if (@file_put_contents($file, (string) $this->now()) !== false) {
            @chmod($file, self::FILE_MODE);
        }
    }

    private function clearFailure(): void
    {
        $file = $this->path(self::FAIL_FILE);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function ensureCacheDir(): bool
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, self::DIR_MODE, true) && !is_dir($this->cacheDir)) {
            return false;
        }
        return is_writable($this->cacheDir);
    }

    private function path(string $name): string
    {
        return rtrim($this->cacheDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    }

    private function today(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
    }

    private function now(): int
    {
        return ($this->clock)()->getTimestamp();
    }

    /**
     * Log a lasting problem at most once per back-off window (retryAfterSeconds),
     * across ALL PHP processes. Under PHP-FPM a static variable is reset on every
     * request, so the window is kept as a small marker file (its modified time).
     * If the marker cannot be written, this process still logs only once per window.
     */
    private function logOnce(string $message): void
    {
        $now = $this->now();
        $key = sha1($this->cacheDir . "\0" . $message);
        $last = self::$logged[$key] ?? null;
        if ($last !== null && $now - $last >= 0 && $now - $last < $this->retryAfterSeconds) {
            return;
        }
        $marker = rtrim($this->logMarkerDir, '/\\') . DIRECTORY_SEPARATOR . 'usdebt-log-' . $key;
        clearstatcache(true, $marker);
        $seen = @filemtime($marker);
        if ($seen !== false && $now - $seen >= 0 && $now - $seen < $this->retryAfterSeconds) {
            self::$logged[$key] = $seen; // another process logged it in this window
            return;
        }
        self::$logged[$key] = $now;
        @touch($marker, $now);
        error_log($message);
    }
}
