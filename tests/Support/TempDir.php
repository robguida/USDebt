<?php

declare(strict_types=1);

namespace USDebt\Tests\Support;

/**
 * Per-test folder under tests/.tmp (gitignored), removed after the test.
 */
trait TempDir
{
    private string $tempDir = '';

    protected function makeTempDir(): string
    {
        $this->tempDir = dirname(__DIR__) . '/.tmp/' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0775, true);
        return $this->tempDir;
    }

    protected function removeTempDir(): void
    {
        if ($this->tempDir === '' || !is_dir($this->tempDir)) {
            return;
        }
        self::removeTree($this->tempDir);
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
