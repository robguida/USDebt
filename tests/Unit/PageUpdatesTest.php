<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use USDebt\Service\ShareLinks;
use USDebt\Service\ShareText;
use USDebt\SiteConfig;

/**
 * Page updates: header logo, saved-tab checkbox, footer copyright, and every
 * link to another site opening in a new tab.
 */
final class PageUpdatesTest extends TestCase
{
    private const SITE = 'https://bengusa.pro/ourusdebt';

    protected function setUp(): void
    {
        putenv(ShareLinks::ENV_BASE_URL . '=' . self::SITE);
    }

    protected function tearDown(): void
    {
        putenv(ShareLinks::ENV_BASE_URL);
    }

    private static function page(array $query = [], array $post = []): string
    {
        return FrontControllerTest::app(FrontControllerTest::source())->handle($query, $post)->body;
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function pages(): array
    {
        return [
            'default' => [[], []],
            'per person' => [['per' => 'person'], []],
            'compare' => [[], ['submit' => 'Compare', 'compare_pres' => ['44', '45']]],
            'bad date' => [['start_date' => '2026-02-30'], []],
        ];
    }

    public function testLogoLinksToTheMakersSiteInANewTabLeftOfTheBrand(): void
    {
        $html = self::page();
        self::assertMatchesRegularExpression(
            '#<a class="logo-link" href="https://bengusa\.pro/" target="_blank" rel="noopener noreferrer">'
            . '<img class="logo" src="images/bengusa-logo\.svg\?r=\d+" width="35" height="32" alt="">'
            . '<span class="sr-only">bengUSA\.pro home \(opens in a new tab\)</span></a>\s*<a class="brand"#',
            $html
        );
        self::assertFileExists(dirname(__DIR__, 2) . '/src/Public/images/bengusa-logo.svg');
    }

    public function testLogoFileHasNothingActive(): void
    {
        $svg = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/images/bengusa-logo.svg');
        self::assertStringStartsWith('<?xml', $svg);
        self::assertLessThan(20000, strlen($svg));
        self::assertDoesNotMatchRegularExpression('#<script|foreignObject|@import|\son\w+\s*=|href\s*=|xlink:|url\(\s*[\'"]?https?:|<image#i', $svg);
    }

    public function testFooterCopyrightLine(): void
    {
        self::assertSame(
            "\u{00A9} 2026 bengUSA.pro. Covers this site and its code, not the public-domain Treasury and Census data.",
            SiteConfig::copyrightLine(new \DateTimeImmutable('2026-12-31 23:59:59', new \DateTimeZone('America/New_York')))
        );
        self::assertSame(
            "\u{00A9} 2026\u{2013}2027 bengUSA.pro. Covers this site and its code, not the public-domain Treasury and Census data.",
            SiteConfig::copyrightLine(new \DateTimeImmutable('2027-01-01 00:00:00', new \DateTimeZone('America/New_York')))
        );
        self::assertSame(
            "\u{00A9} 2026 bengUSA.pro. Covers this site and its code, not the public-domain Treasury and Census data.",
            SiteConfig::copyrightLine(new \DateTimeImmutable('2026-01-01'))
        );
    }

    public function testFooterShowsTheLineUnderTheGitHubLine(): void
    {
        $html = self::page();
        $line = htmlspecialchars(SiteConfig::copyrightLine(new \DateTimeImmutable('now')), ENT_QUOTES);
        self::assertMatchesRegularExpression('#GitHub[^<]*<span class="sr-only">[^<]*</span></a>\.</p>\s*<p class="copyright">' . preg_quote($line, '#') . '</p>#u', $html);
        // The holder and first year live in SiteConfig only.
        self::assertSame('bengUSA.pro', SiteConfig::COPYRIGHT_HOLDER);
        self::assertSame(2026, SiteConfig::COPYRIGHT_FIRST_YEAR);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    #[DataProvider('pages')]
    public function testEveryLinkToAnotherSiteOpensInANewTab(array $query, array $post): void
    {
        $html = self::page($query, $post);
        preg_match_all('#<a\b([^>]*)>#i', $html, $anchors);
        $checked = 0;
        $failed = [];
        foreach ($anchors[1] as $attrs) {
            if (!preg_match('#\bhref="(https?://[^"]*)"#i', $attrs, $m)) {
                continue; // relative, mailto and in-page links stay in the same tab
            }
            $href = html_entity_decode($m[1], ENT_QUOTES);
            if (str_starts_with($href, self::SITE . '/') || $href === self::SITE) {
                continue; // this site's own pages
            }
            $checked++;
            if (!preg_match('#\btarget="_blank"#', $attrs) || !preg_match('#\brel="[^"]*\bnoopener\b[^"]*"#', $attrs)) {
                $failed[] = $href;
            }
        }
        self::assertSame([], $failed, 'links to other sites without target=_blank and rel=noopener');
        if ($query === [] && $post === []) {
            // logo, Debt to the Penny, GitHub, X, Facebook, LinkedIn, Reddit
            self::assertGreaterThanOrEqual(7, $checked);
        }
    }

    public function testOffSiteLinksCarryTheScreenReaderHintAndNoCardOrMailtoIsTouched(): void
    {
        $html = self::page();
        $hint = '<span class="sr-only">' . ShareText::MENU_NEW_TAB . '</span>';
        foreach (['Debt to the Penny', 'GitHub', 'Share to X', 'Share to Facebook', 'Share to LinkedIn', 'Share to Reddit'] as $label) {
            self::assertStringContainsString('>' . $label . $hint . '</a>', $html, $label);
        }
        self::assertDoesNotMatchRegularExpression('#<a[^>]*href="mailto:[^"]*"[^>]*target=#', $html);
        self::assertDoesNotMatchRegularExpression('#<a[^>]*id="share_download"[^>]*target=#', $html);
    }

    public function testShareScriptOnlyChangesHrefsSoTargetAndRelSurvive(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/js/usdebt.js');
        self::assertStringNotContainsString("removeAttribute('target')", $js);
        self::assertStringNotContainsString("removeAttribute('rel')", $js);
        self::assertStringNotContainsString('outerHTML', $js);
        self::assertStringContainsString("a.setAttribute('href', href);", $js);
    }

    public function testSavedTabCheckboxMarkup(): void
    {
        $html = self::page();
        self::assertMatchesRegularExpression('#<div class="tab-memory" id="tab_memory" hidden\s#', $html);
        self::assertStringContainsString('data-path="/ourusdebt/"', $html);
        self::assertStringContainsString('data-saved="Saved. We&apos;ll keep this tab."', $html);
        self::assertStringContainsString('data-removed="Cookie removed."', $html);
        self::assertMatchesRegularExpression('#<input type="checkbox" id="tab_memory_box"(?![^>]*\bchecked)[^>]*> Remember my tab</label>#', $html);
        self::assertStringContainsString(
            '<p class="tab-memory-note" id="tab_memory_note">Saves one cookie on this device. It holds only which tab you picked, nothing else. Untick to remove it.</p>',
            $html
        );
        self::assertMatchesRegularExpression('#<p class="share-status" id="tab_memory_status" role="status"></p>#', $html);
    }

    public function testSavedTabCheckboxIsNotOnTheCompareView(): void
    {
        $html = self::page([], ['submit' => 'Compare', 'compare_pres' => ['44', '45']]);
        self::assertStringNotContainsString('tab_memory', $html);
    }

    public function testCookiePathFollowsTheBaseUrl(): void
    {
        self::assertSame('/ourusdebt/', (new ShareLinks('https://bengusa.pro/ourusdebt'))->cookiePath());
        self::assertSame('/', (new ShareLinks('http://localhost:8000'))->cookiePath());
        self::assertSame('/a/b/', (new ShareLinks('https://x.example/a/b/'))->cookiePath());
    }

    public function testTheScriptHoldsNoWordsForTheSavedTab(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/js/usdebt.js');
        foreach ([ShareText::TAB_MEMORY_LABEL, ShareText::TAB_MEMORY_NOTE, ShareText::TAB_MEMORY_SAVED, ShareText::TAB_MEMORY_REMOVED] as $words) {
            self::assertStringNotContainsString($words, $js);
        }
    }

    public function testTabMemoryCookieLogicInNode(): void
    {
        $finder = DIRECTORY_SEPARATOR === '\\' ? 'where node 2>NUL' : 'command -v node 2>/dev/null';
        $found = trim((string) shell_exec($finder));
        $node = $found === '' ? '' : trim((string) strtok($found, "\r\n"));
        if ($node === '' || !is_file($node)) {
            self::markTestSkipped('Node is not installed.');
        }
        exec(escapeshellarg($node) . ' ' . escapeshellarg(dirname(__DIR__) . '/js/tab-memory.check.js') . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }
}
