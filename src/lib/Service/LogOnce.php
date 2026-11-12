<?php

declare(strict_types=1);

namespace USDebt\Service;

use Closure;

/**
 * Logs a lasting problem at most once per window, across PHP processes.
 * Used by the Treasury cache (DebtRepository) and the share card cache
 * (CardCache).
 *
 * - Shared limit: the window is kept as a marker file's modified time in a
 *   private folder (mode 0700), so every PHP process sees it. That matters
 *   under PHP-FPM (and mod_php), where static state is reset after every
 *   request.
 * - The marker is touched, never written, so it still works on a full disk.
 * - Fallback: if that folder cannot be used safely (see markerDir), no marker
 *   is written and the shared system temp folder is never used instead. The
 *   only limit left is a static variable, which lasts as long as the PHP
 *   process state: under the CLI or a long-running worker that is still once
 *   per window, but under PHP-FPM the line is logged once per request until
 *   the marker folder is fixed.
 */
final class LogOnce
{
    /** The marker folder: only the app's own user may use it. */
    public const MARKER_DIR_MODE = 0700;
    /** Default marker folder name, next to the cache folder (src/var/log-markers by default). */
    public const MARKER_DIR_NAME = 'log-markers';

    /** @var array<string, int> When this PHP process last logged each problem (Unix time). */
    private static array $logged = [];

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param string                $markerDir      Folder for the markers. Never the shared system temp folder.
     * @param int                   $windowSeconds  Log each problem at most once per this many seconds.
     * @param (Closure(): int)|null $clock          "Now" as Unix time; tests pass a fixed time.
     */
    public function __construct(
        private readonly string $markerDir,
        private readonly int $windowSeconds,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The default marker folder for a cache folder: MARKER_DIR_NAME next to it
     * (src/var/log-markers for src/var/cache), not inside it, because the cache
     * folder may be the thing that is broken.
     */
    public static function defaultMarkerDir(string $cacheDir): string
    {
        return dirname(rtrim($cacheDir, '/\\')) . DIRECTORY_SEPARATOR . self::MARKER_DIR_NAME;
    }

    /**
     * Log $message unless the same problem was logged inside the window.
     *
     * @param string $scope  What the problem is about (for example the cache
     *                       folder), so the same words about two folders are
     *                       two problems.
     */
    public function log(string $scope, string $message): void
    {
        $now = ($this->clock)();
        $key = sha1($scope . "\0" . $message);
        $last = self::$logged[$key] ?? null;
        if ($last !== null && $now - $last >= 0 && $now - $last < $this->windowSeconds) {
            return;
        }
        $markerDir = $this->markerDir();
        if ($markerDir !== null) {
            $marker = $markerDir . DIRECTORY_SEPARATOR . $key;
            clearstatcache(true, $marker);
            $seen = @filemtime($marker);
            if ($seen !== false && $now - $seen >= 0 && $now - $seen < $this->windowSeconds) {
                self::$logged[$key] = $seen; // another process logged it in this window
                return;
            }
            @touch($marker, $now);
        }
        self::$logged[$key] = $now;
        error_log($message);
    }

    /**
     * The marker folder, created with mode 0700 if missing. Null when it cannot
     * be used safely: it is (or sits directly in) the shared system temp folder,
     * it is a symlink, it cannot be created or written, or (on POSIX) it belongs
     * to another user or is open to other users.
     */
    private function markerDir(): ?string
    {
        $dir = rtrim($this->markerDir, '/\\');
        if ($dir === '' || is_link($dir)) {
            return null;
        }
        $temp = realpath(sys_get_temp_dir());
        $parent = realpath(dirname($dir));
        if ($temp !== false && ($parent === $temp || realpath($dir) === $temp)) {
            return null;
        }
        if (!is_dir($dir) && !@mkdir($dir, self::MARKER_DIR_MODE, true) && !is_dir($dir)) {
            return null;
        }
        if (is_link($dir) || !is_writable($dir)) {
            return null;
        }
        if (DIRECTORY_SEPARATOR === '/') {
            if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) {
                return null;
            }
            @chmod($dir, self::MARKER_DIR_MODE);
            clearstatcache(true, $dir);
            $perms = @fileperms($dir);
            if ($perms === false || ($perms & 0077) !== 0) {
                return null;
            }
        }
        return $dir;
    }
}
