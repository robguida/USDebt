<?php

declare(strict_types=1);

namespace USDebt\Service;

/**
 * Disk cache for the share card images, one PNG per cache key.
 *
 * - Bounded: at most maxFiles cards. After each save, the oldest cards (by
 *   modified time) are removed until the count is back under the limit.
 * - Keys are sha256 hex strings, so a file name can never leave the folder.
 * - Same file safety as the Treasury cache: temp name then rename, folder
 *   0750, files 0640.
 * - Never fatal. If the folder cannot be used, cards are drawn every time and
 *   the problem is logged once per window across PHP processes (LogOnce, the
 *   same marker files as the Treasury cache), not once per request.
 * - Two requests may trim at the same time. A file the other request already
 *   removed counts as removed, not as a failure.
 */
final class CardCache
{
    public const DIR_NAME = 'cards';
    public const MAX_FILES = 500;
    public const DIR_MODE = 0750;
    public const FILE_MODE = 0640;
    /** Temp files older than this (a crash mid-save) are removed during clean-up. */
    private const STALE_TEMP_SECONDS = 3600;

    /**
     * @param string  $dir       The cards folder. Created if missing. Must not be web-served.
     * @param LogOnce $log       Logs a lasting problem once per window (see CardController::create).
     * @param int     $maxFiles  Most cards kept.
     */
    public function __construct(
        private readonly string $dir,
        private readonly LogOnce $log,
        private readonly int $maxFiles = self::MAX_FILES,
    ) {
    }

    /** The saved card, or null when there is none (or it cannot be read). */
    public function get(string $key): ?string
    {
        $file = $this->file($key);
        if ($file === null || !is_file($file)) {
            return null;
        }
        $png = @file_get_contents($file);
        return is_string($png) && $png !== '' ? $png : null;
    }

    /** Save a card, then trim the folder. False when it could not be saved. */
    public function put(string $key, string $png): bool
    {
        $file = $this->file($key);
        if ($file === null || !$this->ensureDir()) {
            return false;
        }
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $png, LOCK_EX) === false
            || !@chmod($tmp, self::FILE_MODE)
            || !@rename($tmp, $file)) {
            @unlink($tmp);
            $this->warn("UsDebt: could not save a share card in {$this->dir}; cards are drawn on every request until it is fixed.");
            return false;
        }
        $this->trim();
        return true;
    }

    /**
     * Remove the oldest cards until at most maxFiles are left, and any stale
     * temp files. One file that cannot be removed does not stop the rest.
     *
     * @return int  Number of files removed (by this request or, at the same
     *              moment, by another one).
     */
    public function trim(): int
    {
        $cards = @glob($this->dir . DIRECTORY_SEPARATOR . '*.png') ?: [];
        $removed = 0;
        $failed = 0;
        if (count($cards) > $this->maxFiles) {
            $ages = [];
            foreach ($cards as $card) {
                $ages[$card] = @filemtime($card) ?: 0;
            }
            asort($ages);
            $extra = count($cards) - $this->maxFiles;
            foreach (array_keys($ages) as $card) {
                if ($extra <= 0) {
                    break;
                }
                self::remove($card) ? $removed++ : $failed++;
                $extra--;
            }
        }
        $now = time();
        foreach (@glob($this->dir . DIRECTORY_SEPARATOR . '*.tmp') ?: [] as $tmp) {
            $mtime = @filemtime($tmp);
            if ($mtime === false || $now - $mtime <= self::STALE_TEMP_SECONDS) {
                continue; // gone already, or may be another request's save in progress
            }
            self::remove($tmp) ? $removed++ : $failed++;
        }
        if ($failed > 0) {
            $this->warn("UsDebt: could not remove {$failed} old share card file(s) in {$this->dir}.");
        }
        return $removed;
    }

    /**
     * Delete a file. True when it is gone afterwards, including when another
     * request removed it first (two trims at once), so that is not reported
     * as a failure.
     */
    public static function remove(string $file): bool
    {
        if (@unlink($file)) {
            return true;
        }
        clearstatcache(true, $file);
        return !file_exists($file);
    }

    private function file(string $key): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
            return null;
        }
        return $this->dir . DIRECTORY_SEPARATOR . $key . '.png';
    }

    private function ensureDir(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, self::DIR_MODE, true) && !is_dir($this->dir)) {
            $this->warn("UsDebt: could not create the share card folder {$this->dir}; cards are drawn on every request.");
            return false;
        }
        if (!is_writable($this->dir)) {
            $this->warn("UsDebt: the share card folder {$this->dir} is not writable; cards are drawn on every request.");
            return false;
        }
        return true;
    }

    private function warn(string $message): void
    {
        $this->log->log($this->dir, $message);
    }
}
