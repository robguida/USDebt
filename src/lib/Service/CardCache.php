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
 *   the problem is logged once per PHP process.
 */
final class CardCache
{
    public const DIR_NAME = 'cards';
    public const MAX_FILES = 500;
    public const DIR_MODE = 0750;
    public const FILE_MODE = 0640;
    /** Temp files older than this (a crash mid-save) are removed during clean-up. */
    private const STALE_TEMP_SECONDS = 3600;

    private static bool $warned = false;

    public function __construct(
        private readonly string $dir,
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
            $this->warnOnce("UsDebt: could not save a share card in {$this->dir}; cards are drawn on every request until it is fixed.");
            return false;
        }
        $this->trim();
        return true;
    }

    /**
     * Remove the oldest cards until at most maxFiles are left, and any stale
     * temp files. One file that cannot be removed does not stop the rest.
     *
     * @return int  Number of files removed.
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
                if (@unlink($card)) {
                    $removed++;
                } else {
                    $failed++;
                }
                $extra--;
            }
        }
        $now = time();
        foreach (@glob($this->dir . DIRECTORY_SEPARATOR . '*.tmp') ?: [] as $tmp) {
            $mtime = @filemtime($tmp);
            if ($mtime === false || $now - $mtime <= self::STALE_TEMP_SECONDS) {
                continue; // may be another request's save in progress
            }
            if (@unlink($tmp)) {
                $removed++;
            } else {
                $failed++;
            }
        }
        if ($failed > 0) {
            $this->warnOnce("UsDebt: could not remove {$failed} old share card file(s) in {$this->dir}.");
        }
        return $removed;
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
            $this->warnOnce("UsDebt: could not create the share card folder {$this->dir}; cards are drawn on every request.");
            return false;
        }
        if (!is_writable($this->dir)) {
            $this->warnOnce("UsDebt: the share card folder {$this->dir} is not writable; cards are drawn on every request.");
            return false;
        }
        return true;
    }

    private function warnOnce(string $message): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;
        error_log($message);
    }
}
