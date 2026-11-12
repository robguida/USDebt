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
 * - A lock makes sure only one request downloads; the others keep serving the
 *   last good copy (or wait for the download when there is no copy yet).
 * - The file is written to a temp name and renamed, so readers never see half a file.
 * - If the download fails, the last good copy is served (marked stale) and the
 *   download is not retried for RETRY_AFTER_SECONDS, so a Treasury outage does not
 *   make every page wait for the timeout.
 * - If the download fails and there is no copy, DataUnavailableException is thrown.
 */
final class DebtRepository implements DebtSource
{
    public const CACHE_FILE = 'treasury-debt.json';
    public const LOCK_FILE = 'treasury-debt.lock';
    public const FAIL_FILE = 'treasury-debt.failed';
    public const RETRY_AFTER_SECONDS = 900;
    private const FORMAT_VERSION = 1;

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param string                            $cacheDir  Folder for the cache file. Created if missing. Must not be web-served.
     * @param string|null                       $seedFile  JSON rows for days before the API's first day (see Data/debt_seed_1993.json).
     * @param (Closure(): DateTimeImmutable)|null $clock   "Now"; tests pass a fixed time.
     */
    public function __construct(
        private readonly FiscalDataClient $client,
        private readonly string $cacheDir,
        private readonly ?string $seedFile = null,
        ?Closure $clock = null,
        private readonly int $retryAfterSeconds = self::RETRY_AFTER_SECONDS,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    }

    public function load(): DebtDataset
    {
        $today = $this->today();
        $cached = $this->readCache();
        if ($cached !== null && $cached->savedOn() === $today) {
            return $cached;
        }

        if (!$this->ensureCacheDir()) {
            // Cannot save: still serve live data so the site works, and say why in the log.
            error_log("UsDebt: cache folder {$this->cacheDir} is not writable; downloading without saving.");
            return $this->downloadOrFallback($cached, $today, false);
        }

        if ($this->failedRecently()) {
            return $cached !== null ? $this->asStale($cached) : throw new DataUnavailableException();
        }

        $lock = @fopen($this->path(self::LOCK_FILE), 'c');
        if ($lock === false) {
            error_log('UsDebt: could not open the cache lock file; downloading without a lock.');
            return $this->downloadOrFallback($cached, $today, true);
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                // Another request is downloading right now.
                if ($cached !== null) {
                    return $this->asStale($cached);
                }
                flock($lock, LOCK_EX); // wait for it (bounded by the download timeout)
                $fresh = $this->readCache();
                return $fresh ?? throw new DataUnavailableException();
            }
            // We hold the lock. Another request may have finished the download while we waited.
            $cached = $this->readCache() ?? $cached;
            if ($cached !== null && $cached->savedOn() === $today) {
                return $cached;
            }
            return $this->downloadOrFallback($cached, $today, true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function downloadOrFallback(?DebtDataset $cached, string $today, bool $canSave): DebtDataset
    {
        try {
            $result = $this->client->fetchAll();
            if ($result['entries'] === []) {
                throw new \UnexpectedValueException('Treasury returned no usable rows');
            }
        } catch (Throwable $e) {
            error_log('UsDebt: Treasury download failed: ' . get_class($e) . ': ' . $e->getMessage());
            if ($canSave) {
                $this->markFailure();
            }
            if ($cached !== null) {
                return $this->asStale($cached);
            }
            throw new DataUnavailableException($e);
        }

        if ($result['skipped'] > 0) {
            error_log("UsDebt: Treasury download skipped {$result['skipped']} bad row(s).");
        }
        $entries = $this->withSeed($result['entries']);
        if ($canSave) {
            $this->writeCache($entries, $today, $result['skipped']);
            $this->clearFailure();
        }
        return new DebtDataset($entries, $today);
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
     */
    private function writeCache(array $entries, string $today, int $skipped): void
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
            return;
        }
        $target = $this->path(self::CACHE_FILE);
        $tmp = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false || !@rename($tmp, $target)) {
            @unlink($tmp);
            error_log("UsDebt: could not write the cache file {$target}.");
        }
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

    private function asStale(DebtDataset $cached): DebtDataset
    {
        return new DebtDataset($cached->all(), $cached->savedOn(), true);
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
        @file_put_contents($this->path(self::FAIL_FILE), (string) $this->now());
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
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
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
}
