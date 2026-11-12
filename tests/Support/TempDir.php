<?php

declare(strict_types=1);

namespace USDebt\Tests\Support;

/**
 * Per-test folders under tests/.tmp (gitignored), all removed after the test.
 * A test may make more than one; each is tracked, so none is left behind.
 */
trait TempDir
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function makeTempDir(): string
    {
        $dir = dirname(__DIR__) . '/.tmp/' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    protected function removeTempDir(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                self::removeTree($dir);
            }
        }
        $this->tempDirs = [];
    }

    private static function removeTree(string $dir): void
    {
        @chmod($dir, 0775);
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
