<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the project guide's file map honest: every PHP file the app ships
 * (src/lib and src/Public) must be named in CLAUDE.md section 3 ("Layout").
 * A new file without a line in the map fails here, so the map cannot drift.
 */
final class DocsTest extends TestCase
{
    public function testEveryShippedPhpFileIsInTheProjectGuideFileMap(): void
    {
        $root = dirname(__DIR__, 2);
        $guide = (string) file_get_contents($root . '/CLAUDE.md');
        self::assertSame(1, preg_match('/^## 3\. Layout\R(.*?)^## 4\./ms', $guide, $m), 'CLAUDE.md has a "## 3. Layout" section followed by "## 4."');
        $map = $m[1];

        $missing = [];
        foreach (['src/lib', 'src/Public'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $name = $file->getFilename();
                if (!preg_match('/(?<![A-Za-z0-9_.-])' . preg_quote($name, '/') . '(?![A-Za-z0-9_])/', $map)) {
                    $missing[] = $dir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/' . $dir) + 1));
                }
            }
        }
        sort($missing);
        self::assertSame([], $missing, 'Add these files to the file map in CLAUDE.md section 3.');
    }
}
