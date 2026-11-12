<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use USDebt\Controller\CardController;
use USDebt\Controller\FrontController;
use USDebt\Controller\USDebtController;
use USDebt\Exception\DataUnavailableException;
use USDebt\Http\Response;
use USDebt\Model\DebtDataset;
use USDebt\Model\DebtEntry;
use USDebt\Model\Per;
use USDebt\Service\CardCache;
use USDebt\Service\CardRenderer;
use USDebt\Service\CompareService;
use USDebt\Service\DebtRepository;
use USDebt\Service\DebtSource;
use USDebt\Service\FiscalDataClient;
use USDebt\Service\LogOnce;
use USDebt\Service\PresidentService;
use USDebt\Service\ShareCardBuilder;
use USDebt\Service\ShareLinks;
use USDebt\Service\ShareText;
use USDebt\Tests\Support\FakeHttpFetcher;
use USDebt\SiteConfig;
use USDebt\Tests\Support\TempDir;

/**
 * Share link + picture card: Compare by link, the link preview tags, and the
 * card image endpoint (card.php).
 */
final class ShareTest extends TestCase
{
    use TempDir;

    private const BASE = 'https://usdebt.bengusa.pro';
    private const CLINTON = '727506000';
    private const BUSH = '979966800';
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** sha256 of DejaVu Sans 2.37 (dejavu-fonts-ttf-2.37.zip, GitHub release; same bytes on npm dejavu-fonts-ttf@2.37.3). */
    private const FONT_SHA256 = [
        'DejaVuSans.ttf' => '7da195a74c55bef988d0d48f9508bd5d849425c1770dba5d7bfc6ce9ed848954',
        'DejaVuSans-Bold.ttf' => 'e6476c1b80502924294eed40894c5b18e06c181444ca953e5334262df9c27724',
    ];

    protected function tearDown(): void
    {
        $this->removeTempDir();
        putenv(ShareLinks::ENV_BASE_URL);
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
    }

    private static function page(array $query, array $post = []): Response
    {
        return FrontControllerTest::app(FrontControllerTest::source())->handle($query, $post);
    }

    private function cards(?DebtSource $source = null, ?string $cacheDir = null): CardController
    {
        return new CardController(
            $source ?? FrontControllerTest::source(),
            new CompareService(),
            new CardRenderer('usdebt.bengusa.pro'),
            $this->cardCache($cacheDir ?? $this->makeTempDir() . '/cards'),
        );
    }

    /** A card cache whose "already logged" markers sit in this test's own temp folder. */
    private function cardCache(string $dir, int $maxFiles = CardCache::MAX_FILES): CardCache
    {
        return new CardCache($dir, new LogOnce($this->makeTempDir() . '/log-markers', 900), $maxFiles);
    }

    private static function requireGd(): void
    {
        if (!CardRenderer::gdAvailable()) {
            self::markTestSkipped('PHP gd with FreeType is not loaded. Run: php -d extension=gd vendor/bin/phpunit');
        }
    }

    /** Daily rows for the last 90 days, so the default range (the last month) always has data. */
    private static function recentSource(): DebtSource
    {
        $entries = [];
        $day = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
        for ($i = 90; $i >= 0; $i--) {
            $entries[] = new DebtEntry($day->modify("-{$i} days")->format('Y-m-d'), 38.0e12 + (90 - $i) * 1.0e9);
        }
        return FrontControllerTest::sourceOf(...$entries);
    }

    /** @return array<string, string> property/name => content of every og: and twitter: meta tag */
    private static function meta(string $html): array
    {
        preg_match_all('#<meta (?:property|name)="((?:og|twitter):[a-z:_]+)" content="([^"]*)">#', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $name, $content]) {
            $out[$name] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $out;
    }

    private static function assertPng(Response $response): void
    {
        self::assertSame(200, $response->status, $response->body);
        self::assertSame('image/png', $response->headers['Content-Type']);
        self::assertSame('public, max-age=3600', $response->headers['Cache-Control']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertSame((string) strlen($response->body), $response->headers['Content-Length']);
        self::assertStringStartsWith(self::PNG_SIGNATURE, $response->body);
        $info = getimagesizefromstring($response->body);
        self::assertIsArray($info);
        self::assertSame([1200, 630], [$info[0], $info[1]]);
        self::assertSame('image/png', $info['mime']);
    }

    private static function assertPlainError(int $status, Response $response): void
    {
        self::assertSame($status, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertStringNotContainsString(self::PNG_SIGNATURE, $response->body, 'no image on an error');
        self::assertStringNotContainsString('<', $response->body);
    }

    /** @return list<string> Card files in the cache folder. */
    private static function cardFiles(string $dir): array
    {
        return glob($dir . '/*.png') ?: [];
    }

    // ---------- Compare by link ----------

    public function testCompareByLinkShowsTheSameViewAsTheForm(): void
    {
        $posted = self::page([], ['submit' => 'Compare', 'compare_pres' => [self::CLINTON, self::BUSH]]);
        $linked = self::page(['compare' => self::CLINTON . ',' . self::BUSH]);

        self::assertSame(200, $linked->status);
        self::assertSame(FrontControllerTest::chartJson($posted->body), FrontControllerTest::chartJson($linked->body));
        self::assertSame(2, substr_count($linked->body, 'checked="checked"'), 'the picked boxes are ticked');
        self::assertStringContainsString('<title>Compare presidential terms - Our U.S. Debt</title>', $linked->body);
    }

    public function testCompareLinkIgnoresUnknownAndDuplicatePicksLikeTheForm(): void
    {
        $clean = FrontControllerTest::chartJson(self::page(['compare' => self::CLINTON . ',' . self::BUSH])->body);

        foreach ([
            self::BUSH . ',bogus,' . self::CLINTON . ',' . self::BUSH . ',,-1,1e3',
            self::CLINTON . ',' . self::CLINTON . ',' . self::BUSH,
        ] as $messy) {
            self::assertSame($clean, FrontControllerTest::chartJson(self::page(['compare' => $messy])->body), $messy);
        }
        // The list form (compare[]=...) is read the same way.
        self::assertSame($clean, FrontControllerTest::chartJson(self::page(['compare' => [self::CLINTON, 'x', self::BUSH]])->body));
    }

    public function testCompareLinkHasNoCap(): void
    {
        $all = implode(',', array_keys(PresidentService::getPresidentConfig()));
        $chart = FrontControllerTest::chartJson(self::page(['compare' => $all])->body);

        self::assertCount(5, $chart['series'], 'every president; both Trump terms are one line');
    }

    public function testCompareLinkNeedsTwoValidPicks(): void
    {
        foreach ([self::CLINTON, self::CLINTON . ',' . self::CLINTON, 'bogus', '', [['nested']]] as $picks) {
            $response = self::page(['compare' => $picks]);
            self::assertSame(400, $response->status, var_export($picks, true));
            self::assertStringContainsString('Please pick at least 2 presidents to compare.', $response->body);
        }
    }

    public function testThePostedFormStillWorksAndWinsOverTheLink(): void
    {
        $all = array_map('strval', array_keys(PresidentService::getPresidentConfig()));
        $response = self::page(['compare' => self::CLINTON . ',' . self::BUSH], ['submit' => 'Compare', 'compare_pres' => $all]);

        self::assertSame(200, $response->status);
        self::assertCount(5, FrontControllerTest::chartJson($response->body)['series']);
    }

    public function testCompareLinkKeepsTheTrendSwitch(): void
    {
        $html = self::page(['compare' => self::CLINTON . ',' . self::BUSH, 'trend' => '0'])->body;

        self::assertStringContainsString('id="trend_toggle" aria-pressed="false"', $html);
    }

    // ---------- Link preview tags ----------

    public function testRangePageHasAbsolutePreviewTagsForThatView(): void
    {
        $html = self::page(['start_date' => '2020-01-01', 'end_date' => '2026-11-09'])->body;
        $meta = self::meta($html);
        $points = FrontControllerTest::chartJson($html)['series'][0]['points'];
        $first = $points[0][0];
        $last = $points[count($points) - 1][0];

        self::assertSame('website', $meta['og:type']);
        self::assertSame('Our U.S. Debt', $meta['og:site_name']);
        self::assertSame('U.S. debt, ' . self::short($first) . ' to ' . self::short($last), $meta['og:title']);
        self::assertMatchesRegularExpression('/^Debt grew \$[0-9.]+ trillion over this period\. Daily U\.S\. Treasury data\.$/', $meta['og:description']);
        self::assertMatchesRegularExpression(
            '/^' . preg_quote('Chart of U.S. debt from ' . self::short($first) . ' to ' . self::short($last) . '. Debt grew $', '/') . '[0-9.]+ trillion\.$/',
            $meta['og:image:alt'],
        );
        self::assertSame(self::BASE . '/?start_date=2020-01-01&end_date=2026-11-09', $meta['og:url']);
        // The card link uses the first and last day that has data.
        self::assertSame(self::BASE . "/card.php?start_date={$first}&end_date={$last}", $meta['og:image']);
        self::assertSame(['1200', '630', 'image/png'], [$meta['og:image:width'], $meta['og:image:height'], $meta['og:image:type']]);
        self::assertSame('summary_large_image', $meta['twitter:card']);
        self::assertSame($meta['og:title'], $meta['twitter:title']);
        self::assertSame($meta['og:image'], $meta['twitter:image']);
        self::assertSame($meta['og:image:alt'], $meta['twitter:image:alt']);
        foreach (['og:url', 'og:image', 'twitter:image'] as $link) {
            self::assertStringStartsWith(self::BASE . '/', $meta[$link], $link);
        }
        // The page escapes the & in the links.
        self::assertStringContainsString('start_date=2020-01-01&amp;end_date=2026-11-09', $html);
    }

    public function testTrendOffIsInThePageLinkAndTheCardLink(): void
    {
        $meta = self::meta(self::page(['start_date' => '2026-01-01', 'end_date' => '2026-11-09', 'trend' => '0'])->body);

        self::assertStringEndsWith('&trend=0', $meta['og:url']);
        self::assertStringEndsWith('&trend=0', $meta['og:image']);
    }

    public function testHomePageSharesAsTheHomePage(): void
    {
        $meta = self::meta(FrontControllerTest::app(self::recentSource())->handle([], [])->body);

        self::assertSame(self::BASE . '/', $meta['og:url']);
        self::assertMatchesRegularExpression('#^' . preg_quote(self::BASE, '#') . '/card\.php\?start_date=\d{4}-\d{2}-\d{2}&end_date=\d{4}-\d{2}-\d{2}$#', $meta['og:image']);
    }

    public function testComparePageSharesTheCompareLink(): void
    {
        $meta = self::meta(self::page([], ['submit' => 'Compare', 'compare_pres' => [self::BUSH, self::CLINTON, self::BUSH]])->body);

        // Term order, no duplicates, whatever order they were sent in.
        self::assertSame(self::BASE . '/?compare=' . self::CLINTON . ',' . self::BUSH, $meta['og:url']);
        self::assertSame(self::BASE . '/card.php?compare=' . self::CLINTON . ',' . self::BUSH, $meta['og:image']);
        self::assertSame('U.S. debt across presidential terms', $meta['og:title']);
        self::assertSame(
            'Change in debt during each term, side by side. Congress sets spending and taxes; terms are shown for reference only.',
            $meta['og:description'],
        );
        self::assertSame(ShareText::COMPARE_ALT, $meta['og:image:alt']);
    }

    public function testErrorPageGetsTheSiteWidePreview(): void
    {
        $meta = self::meta(self::page(['start_date' => '2026-02-30'])->body);

        // The home page title is the site's name, from its one place.
        self::assertSame(SiteConfig::NAME, $meta['og:title']);
        self::assertSame(ShareText::HOME_DESCRIPTION, $meta['og:description']);
        self::assertSame(ShareText::HOME_ALT, $meta['og:image:alt']);
        self::assertSame(self::BASE . '/', $meta['og:url']);
        self::assertSame(self::BASE . '/card.php', $meta['og:image']);
        self::assertSame('summary_large_image', $meta['twitter:card']);
    }

    // ---------- Share button and menu ----------

    /**
     * The Share menu's parts.
     *
     * @return array{menu: string, attrs: array<string, string>, url: string, links: array<string, string>, tags: array<string, string>, download: array<string, string>, thumb: array<string, string>}
     */
    private static function shareMenu(string $html): array
    {
        self::assertSame(1, preg_match('#<details class="share" id="share_menu"([^>]*)>(.*?)</details>#s', $html, $m), 'one Share menu');
        [, $attrText, $menu] = $m;
        $decode = static fn (string $v): string => html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $attrsOf = static function (string $tag) use ($decode): array {
            preg_match_all('#([a-z-]+)="([^"]*)"#', $tag, $pairs, PREG_SET_ORDER);
            $out = [];
            foreach ($pairs as [, $k, $v]) {
                $out[$k] = $decode($v);
            }
            return $out;
        };
        self::assertSame(1, preg_match('#<input type="text" class="share-url" id="share_url"[^>]*>#', $menu, $input));
        self::assertSame(1, preg_match('#<a class="share-item" id="share_download"[^>]*>#', $menu, $download));
        self::assertSame(1, preg_match('#<img class="share-thumb"[^>]*>#', $menu, $thumb));
        preg_match_all('#<a class="share-item" data-share="([a-z]+)"[^>]*>#', $menu, $found, PREG_SET_ORDER);
        $links = [];
        $tags = [];
        foreach ($found as [$tag, $key]) {
            $links[$key] = $attrsOf($tag)['href'];
            $tags[$key] = $tag;
        }
        return [
            'menu' => $menu,
            'attrs' => $attrsOf($attrText),
            'url' => $attrsOf($input[0])['value'],
            'links' => $links,
            'tags' => $tags,
            'download' => $attrsOf($download[0]),
            'thumb' => $attrsOf($thumb[0]),
        ];
    }

    public function testRangePageHasAShareButtonAndMenu(): void
    {
        $html = self::page(['start_date' => '2009-01-20', 'end_date' => '2017-01-19'])->body;
        $meta = self::meta($html);
        $share = self::shareMenu($html);

        // The button sits in the chart card's header, next to the Trend line switch.
        self::assertMatchesRegularExpression('#<div class="card-actions">\s*<button[^>]*id="trend_toggle"[^>]*>.*?</button>\s*<details class="share" id="share_menu"#s', $html);
        self::assertStringContainsString('<summary class="btn btn-quiet share-button">Share</summary>', $share['menu']);
        foreach (['Share this view', 'Copy link', 'Download picture', 'Share to X', 'Share to Facebook', 'Share to LinkedIn', 'Share to Reddit', 'Share by email'] as $label) {
            self::assertStringContainsString('>' . $label . '<', $share['menu'], $label);
        }

        // The link to share is the view's own GET link: the same as og:url.
        self::assertSame(self::BASE . '/?start_date=2009-01-20&end_date=2017-01-19', $meta['og:url']);
        self::assertSame($meta['og:url'], $share['url']);
        self::assertStringContainsString('<label class="sr-only" for="share_url">', $share['menu']);
        self::assertStringContainsString(' readonly', $share['menu']);

        // Plain links with the encoded link and share text: no scripts from the social sites.
        $text = $share['attrs']['data-text'];
        // The words use the first and last day that has data (the same days as the card).
        parse_str((string) parse_url($meta['og:image'], PHP_URL_QUERY), $days);
        $span = self::short($days['start_date']) . ' to ' . self::short($days['end_date']);
        self::assertMatchesRegularExpression('/^U\.S\. debt grew \$[0-9.]+ trillion from ' . preg_quote($span, '/') . '\.$/', $text);
        self::assertSame('U.S. debt, ' . $span, $share['attrs']['data-subject']);
        self::assertSame(ShareLinks::networks($meta['og:url'], $text, $share['attrs']['data-subject']), $share['links']);
        $encodedUrl = 'https%3A%2F%2Fusdebt.bengusa.pro%2F%3Fstart_date%3D2009-01-20%26end_date%3D2017-01-19';
        self::assertSame('https://x.com/intent/tweet?text=' . rawurlencode($text) . '&url=' . $encodedUrl, $share['links']['x']);
        self::assertSame('https://www.facebook.com/sharer/sharer.php?u=' . $encodedUrl, $share['links']['facebook']);
        self::assertSame('https://www.linkedin.com/sharing/share-offsite/?url=' . $encodedUrl, $share['links']['linkedin']);
        self::assertSame('https://www.reddit.com/submit?url=' . $encodedUrl . '&title=' . rawurlencode($text), $share['links']['reddit']);
        self::assertSame(
            'mailto:?subject=' . rawurlencode('U.S. debt, ' . $span) . '&body=' . rawurlencode($text) . '%20' . $encodedUrl
            . '%0A%0AData%3A%20U.S.%20Treasury%2C%20Debt%20to%20the%20Penny.',
            $share['links']['email'],
        );
        foreach (['x', 'facebook', 'linkedin', 'reddit'] as $key) {
            self::assertStringContainsString(' target="_blank" rel="noopener noreferrer"', $share['tags'][$key], $key);
        }
        self::assertStringNotContainsString('target=', $share['tags']['email']);

        // Download picture and the preview: the card for this view, on this site.
        $card = '/card.php?' . parse_url($meta['og:image'], PHP_URL_QUERY);
        self::assertSame($card, $share['download']['href']);
        self::assertSame('us-debt-' . $days['start_date'] . '-to-' . $days['end_date'] . '.png', $share['download']['download']);
        self::assertSame($card, $share['thumb']['src']);
        self::assertSame('lazy', $share['thumb']['loading']);
        self::assertSame($meta['og:image:alt'], $share['thumb']['alt']);
        self::assertSame(['1200', '630'], [$share['thumb']['width'], $share['thumb']['height']]);
        self::assertSame('1', $share['attrs']['data-card-trend'], 'a range card follows the trend switch');

        // Script-only parts start hidden; nothing inline.
        self::assertStringContainsString('id="share_copy" hidden', $share['menu']);
        self::assertStringContainsString('id="share_native" hidden', $share['menu']);
        self::assertDoesNotMatchRegularExpression('#<script|\son[a-z]+\s*=|\sstyle\s*=|javascript:#i', $share['menu']);
    }

    public function testShareMenuAfterAComparePostSharesTheGetLink(): void
    {
        $html = self::page([], ['submit' => 'Compare', 'compare_pres' => [self::BUSH, self::CLINTON]])->body;
        $meta = self::meta($html);
        $share = self::shareMenu($html);
        $link = self::BASE . '/?compare=' . self::CLINTON . ',' . self::BUSH;

        self::assertSame($link, $meta['og:url']);
        self::assertSame($link, $share['url']);
        self::assertStringContainsString('url=' . rawurlencode($link), $share['links']['x']);
        self::assertSame('How the U.S. debt changed across presidential terms.', $share['attrs']['data-text']);
        self::assertSame('U.S. debt across presidential terms', $share['attrs']['data-subject']);
        self::assertSame('/card.php?compare=' . self::CLINTON . ',' . self::BUSH, $share['download']['href']);
        self::assertSame('us-debt-presidential-terms.png', $share['download']['download']);
        self::assertSame(ShareText::COMPARE_ALT, $share['thumb']['alt']);
        self::assertSame('0', $share['attrs']['data-card-trend'], 'the Compare card has no trend line');
    }

    public function testCompareCardWordsUseTheYearsThatHaveData(): void
    {
        $source = FrontControllerTest::sourceOf(
            new DebtEntry('1993-03-01', 4.2e12),
            new DebtEntry('2000-12-29', 5.6e12),
            new DebtEntry('2001-02-01', 5.7e12),
            new DebtEntry('2008-12-31', 10.7e12),
        );
        $card = (new ShareCardBuilder(new CompareService()))->forCompare($source->load(), [(int) self::CLINTON, (int) self::BUSH], true);

        self::assertNotNull($card);
        self::assertSame('U.S. debt across presidential terms', $card->headline);
        self::assertSame('Change in debt during each term, 1993 to 2008', $card->detail);
        self::assertSame('Congress sets spending and taxes. Terms are shown for reference only.', $card->note);
        self::assertFalse($card->cardTrendMatters);
    }

    public function testCompareTotalsRunToTheLastDataDayOfEachTermOnTheCardAndThePage(): void
    {
        // Each term's last month has two data days: the first day of the month
        // (the last monthly chart point) and the term's real last data day.
        $source = FrontControllerTest::sourceOf(
            new DebtEntry('1993-01-20', 4.00e12),
            new DebtEntry('2001-01-02', 5.60e12),
            new DebtEntry('2001-01-19', 5.70e12),   // Clinton's last data day
            new DebtEntry('2001-01-22', 5.72e12),
            new DebtEntry('2009-01-02', 10.60e12),
            new DebtEntry('2009-01-16', 10.63e12),  // Bush's last data day
            new DebtEntry('2017-01-20', 19.94e12),
            new DebtEntry('2021-01-04', 27.75e12),
            new DebtEntry('2021-01-19', 27.80e12),  // Trump term 1, last data day
            new DebtEntry('2025-01-21', 36.20e12),
            new DebtEntry('2026-11-02', 38.00e12),
            new DebtEntry('2026-11-09', 38.10e12),  // Trump term 2, latest data day
        );
        $trump = [1484888400, 1863561600];
        $keys = [(int) self::CLINTON, (int) self::BUSH, ...$trump];
        $card = (new ShareCardBuilder(new CompareService()))->forCompare($source->load(), $keys, true);

        // First to last data day of each term. Two terms of one president are
        // the sum of each term's change (7.86 + 1.90), the same number the
        // joined line on the Compare chart ends on.
        self::assertNotNull($card);
        self::assertSame([
            ['label' => 'Bill Clinton (1993-2001)', 'change' => 1.70e12],
            ['label' => 'George W. Bush (2001-2009)', 'change' => 4.91e12],
            ['label' => 'Donald Trump (2017-2021, 2025-2029)', 'change' => 9.76e12],
        ], $card->rows);
        self::assertSame(['+$1.70 trillion', '+$4.91 trillion', '+$9.76 trillion'], array_map(
            static fn (array $row): string => ShareText::compareChange($row['change']),
            $card->rows,
        ));

        // The Compare page states the very same numbers.
        $html = FrontControllerTest::app($source)->handle(['compare' => implode(',', $keys)], [])->body;
        self::assertStringContainsString('Bill Clinton (1993-2001): +$1.70 trillion after 2 months in office.', $html);
        self::assertStringContainsString('George W. Bush (2001-2009): +$4.91 trillion after 2 months in office.', $html);
        self::assertStringContainsString('Donald Trump (2017-2021, 2025-2029): +$9.76 trillion after 4 months in office.', $html);
    }

    public function testShareMenuKeepsTheTrendSwitch(): void
    {
        $html = self::page(['start_date' => '2026-01-01', 'end_date' => '2026-11-09', 'trend' => '0'])->body;
        $share = self::shareMenu($html);

        self::assertStringEndsWith('&trend=0', $share['url']);
        self::assertStringEndsWith('%26trend%3D0', $share['links']['facebook']);
        self::assertStringEndsWith('&trend=0', $share['download']['href']);
    }

    public function testHomePageSharesTheHomeLinkWithTheRangeWords(): void
    {
        $html = FrontControllerTest::app(self::recentSource())->handle([], [])->body;
        $meta = self::meta($html);
        $share = self::shareMenu($html);

        self::assertSame(SiteConfig::NAME, $meta['og:title']);
        self::assertSame(ShareText::HOME_DESCRIPTION, $meta['og:description']);
        self::assertSame(self::BASE . '/', $share['url']);
        self::assertStringStartsWith('U.S. debt grew $', $share['attrs']['data-text']);
    }

    public function testNoShareMenuWithoutSomethingToShare(): void
    {
        self::assertStringNotContainsString('share_menu', self::page(['start_date' => '2026-02-30'])->body, 'error page');
        self::assertStringNotContainsString('share_menu', self::page(['start_date' => '1990-01-01', 'end_date' => '1990-02-01'])->body, 'no data');
    }

    public function testScriptBuildsTheSameShareLinksAsTheServer(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/js/usdebt.js');
        // No social-site scripts or SDKs, the Clipboard API with a fallback, Esc closes, the phone's share sheet.
        foreach (['platform.twitter.com', 'connect.facebook.net', 'platform.linkedin.com', 'embed.reddit'] as $sdk) {
            self::assertStringNotContainsString($sdk, $js);
        }
        self::assertStringContainsString('navigator.clipboard.writeText', $js);
        self::assertStringContainsString("document.execCommand('copy')", $js);
        self::assertStringContainsString("e.key === 'Escape'", $js);
        self::assertStringContainsString("typeof navigator.share === 'function'", $js);
        self::assertStringContainsString("document.querySelector('meta[property=\"og:url\"]')", $js);

        $node = self::findNode();
        if ($node === null) {
            self::markTestSkipped('Node is not installed; the static checks above still ran.');
        }
        $pages = [];
        $views = [
            'range' => [['start_date' => '2009-01-20', 'end_date' => '2017-01-19'], [], true],
            'range, trend off' => [['start_date' => '2009-01-20', 'end_date' => '2017-01-19', 'trend' => '0'], [], false],
            'compare post' => [[], ['submit' => 'Compare', 'compare_pres' => [self::CLINTON, self::BUSH]], true],
            'compare link, trend off' => [['compare' => self::CLINTON . ',' . self::BUSH, 'trend' => '0'], [], false],
        ];
        foreach ($views as $name => [$query, $post, $trendOn]) {
            $html = self::page($query, $post)->body;
            $share = self::shareMenu($html);
            $pages[] = [
                'name' => $name,
                'ogUrl' => self::meta($html)['og:url'],
                'trendOn' => $trendOn,
                'text' => $share['attrs']['data-text'],
                'subject' => $share['attrs']['data-subject'],
                'note' => $share['attrs']['data-note'],
                'links' => $share['links'],
            ];
        }
        $file = $this->makeTempDir() . '/share-pages.json';
        file_put_contents($file, json_encode($pages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $check = dirname(__DIR__) . '/js/share-url.check.js';
        exec(escapeshellarg($node) . ' ' . escapeshellarg($check) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    private static function findNode(): ?string
    {
        $finder = DIRECTORY_SEPARATOR === '\\' ? 'where node 2>NUL' : 'command -v node 2>/dev/null';
        $found = trim((string) shell_exec($finder));
        $first = $found === '' ? '' : trim(strtok($found, "\r\n"));
        return $first !== '' && is_file($first) ? $first : null;
    }

    public function testBaseUrlComesFromConfigurationNeverFromTheHostHeader(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.example';
        $_SERVER['SERVER_NAME'] = 'evil.example';
        putenv(ShareLinks::ENV_BASE_URL . '=https://Example.test/usdebt/');
        $app = new FrontController(new USDebtController(FrontControllerTest::source(), new CompareService(), ShareLinks::fromEnvironment()));
        $html = $app->handle(['start_date' => '2026-01-01', 'end_date' => '2026-11-09'], [])->body;
        $meta = self::meta($html);

        self::assertSame('https://example.test/usdebt/?start_date=2026-01-01&end_date=2026-11-09', $meta['og:url']);
        self::assertStringStartsWith('https://example.test/usdebt/card.php?', $meta['og:image']);
        self::assertStringNotContainsString('evil.example', $html);

        // No code reads the Host header or the server name.
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                self::assertDoesNotMatchRegularExpression(
                    '/HTTP_HOST|SERVER_NAME|HTTP_X_FORWARDED/',
                    (string) file_get_contents($file->getPathname()),
                    $file->getPathname()
                );
            }
        }
    }

    public function testDefaultBaseUrlIsTheProductionAddress(): void
    {
        putenv(ShareLinks::ENV_BASE_URL);
        self::assertSame(self::BASE, ShareLinks::fromEnvironment()->baseUrl());
        self::assertSame('usdebt.bengusa.pro', ShareText::siteAddress(self::BASE));
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function baseUrls(): array
    {
        return [
            'plain' => ['https://usdebt.bengusa.pro', 'https://usdebt.bengusa.pro'],
            'trailing slash' => ['https://usdebt.bengusa.pro/', 'https://usdebt.bengusa.pro'],
            'upper case' => ['HTTPS://UsDebt.Bengusa.PRO', 'https://usdebt.bengusa.pro'],
            'port and path' => ['http://localhost:8000/app/', 'http://localhost:8000/app'],
            'no scheme' => ['usdebt.bengusa.pro', null],
            'protocol relative' => ['//evil.example', null],
            'javascript' => ['javascript:alert(1)', null],
            'other scheme' => ['ftp://usdebt.bengusa.pro', null],
            'query' => ['https://usdebt.bengusa.pro/?x=1', null],
            'fragment' => ['https://usdebt.bengusa.pro/#x', null],
            'user' => ['https://user@evil.example', null],
            'quote in path' => ['https://usdebt.bengusa.pro/"><script>', null],
            'bad host' => ['https://evil_example.com', null],
        ];
    }

    #[DataProvider('baseUrls')]
    public function testBaseUrlIsAPlainHttpAddress(string $value, ?string $expected): void
    {
        self::assertSame($expected, ShareLinks::normalize($value));
        self::assertSame($expected ?? ShareLinks::DEFAULT_BASE_URL, (new ShareLinks($value))->baseUrl(), 'a bad value falls back to the default');
    }

    // ---------- Words ----------

    public function testHeadlineWords(): void
    {
        $a = new DebtEntry('2009-01-20', 10.63e12);
        $b = new DebtEntry('2017-01-19', 19.95e12);

        $down = [new DebtEntry('2017-01-19', 19.95e12), new DebtEntry('2009-01-20', 10.63e12)];
        $flat = new DebtEntry('2017-01-19', 10.63e12);

        // Card headline and the line under it.
        self::assertSame('U.S. debt grew $9.32 trillion', ShareText::rangeHeadline($a, $b));
        self::assertSame('U.S. debt fell $9.32 trillion', ShareText::rangeHeadline(...$down));
        self::assertSame('U.S. debt was about the same', ShareText::rangeHeadline($a, $flat));
        self::assertSame('U.S. debt: $10.63 trillion', ShareText::rangeHeadline($a, $a));
        self::assertSame('Jan 20, 2009 to Jan 19, 2017', ShareText::rangeDetail($a, $b));
        self::assertSame('Jan 20, 2009', ShareText::rangeDetail($a, $a));

        // Share text (the link is added by each site).
        self::assertSame('U.S. debt grew $9.32 trillion from Jan 20, 2009 to Jan 19, 2017.', ShareText::rangeShareText($a, $b));
        self::assertSame('U.S. debt fell $9.32 trillion from Jan 19, 2017 to Jan 20, 2009.', ShareText::rangeShareText(...$down));
        self::assertSame('U.S. debt was about the same from Jan 20, 2009 to Jan 19, 2017.', ShareText::rangeShareText($a, $flat));
        self::assertSame('U.S. debt on Jan 20, 2009: $10.63 trillion.', ShareText::rangeShareText($a, $a));
        self::assertSame('U.S. debt, Jan 20, 2009 to Jan 19, 2017', ShareText::rangeEmailSubject($a, $b));
        self::assertSame("U.S. debt grew. https://x.test/\n\nData: U.S. Treasury, Debt to the Penny.", ShareText::emailBody('U.S. debt grew.', 'https://x.test/'));

        // Link preview and alt text.
        self::assertSame('U.S. debt, Jan 20, 2009 to Jan 19, 2017', ShareText::rangeTitle($a, $b));
        self::assertSame('Debt grew $9.32 trillion over this period. Daily U.S. Treasury data.', ShareText::rangeDescription($a, $b));
        self::assertSame('Debt was about the same over this period. Daily U.S. Treasury data.', ShareText::rangeDescription($a, $flat));
        self::assertSame('Chart of U.S. debt from Jan 20, 2009 to Jan 19, 2017. Debt grew $9.32 trillion.', ShareText::rangeAlt($a, $b));
        self::assertSame('Chart of U.S. debt from Jan 19, 2017 to Jan 20, 2009. Debt fell $9.32 trillion.', ShareText::rangeAlt(...$down));

        // Compare.
        self::assertSame('Change in debt during each term, 1993 to 2026', ShareText::compareDetail(1993, 2026));
        self::assertSame('How the U.S. debt changed across presidential terms.', ShareText::COMPARE_SHARE_TEXT);
        self::assertSame('Congress sets spending and taxes. Terms are shown for reference only.', ShareText::COMPARE_NOTE);
    }

    public function testShareWordsStayNeutral(): void
    {
        $words = [ShareText::HOME_TITLE, ShareText::HOME_DESCRIPTION, ShareText::HOME_ALT, ShareText::COMPARE_TITLE,
            ShareText::COMPARE_DESCRIPTION, ShareText::COMPARE_NOTE, ShareText::COMPARE_SHARE_TEXT, ShareText::COMPARE_ALT,
            ShareText::EMAIL_NOTE, ShareText::SOURCE];
        $a = new DebtEntry('2009-01-20', 10.63e12);
        $b = new DebtEntry('2017-01-19', 19.95e12);
        foreach (['rangeHeadline', 'rangeDetail', 'rangeTitle', 'rangeDescription', 'rangeAlt', 'rangeShareText', 'rangeEmailSubject'] as $method) {
            $words[] = ShareText::$method($a, $b);
            $words[] = ShareText::$method($b, $a);
        }
        foreach ($words as $text) {
            self::assertDoesNotMatchRegularExpression('/\b(under|because|thanks to|blame|record|surge|crisis|Democrat|Republican)\b/i', $text);
        }
    }

    private static function short(string $isoDate): string
    {
        return (new DateTimeImmutable($isoDate))->format('M j, Y');
    }

    // ---------- The card image ----------

    public function testCardForADateRangeIsA1200By630Png(): void
    {
        self::requireGd();
        self::assertPng($this->cards()->handle(['start_date' => '2009-01-20', 'end_date' => '2017-01-19']));
        self::assertPng($this->cards()->handle(['start_date' => '2009-01-20', 'end_date' => '2017-01-19', 'trend' => '0']));
        self::assertPng($this->cards()->handle(['start_date' => '2026-11-09', 'end_date' => '2026-11-09']), 'one day');
        self::assertPng($this->cards(self::recentSource())->handle([]), 'no parameters: the default range');
    }

    public function testCardForCompareIsA1200By630Png(): void
    {
        self::requireGd();
        $all = implode(',', array_keys(PresidentService::getPresidentConfig()));
        self::assertPng($this->cards()->handle(['compare' => $all]));
        self::assertPng($this->cards()->handle(['compare' => self::CLINTON . ',' . self::BUSH]));
    }

    public function testCardWithManyTermsStillFits(): void
    {
        self::requireGd();
        $source = FrontControllerTest::source();
        // Names far longer than any real one, each with two terms, so every
        // label is cut to fit its column ("…").
        $terms = [];
        for ($i = 0; $i < 10; $i++) {
            $start = 1993 + 3 * $i;
            $name = "President number {$i} with a much longer name than any real president";
            $terms[1000 + 2 * $i] = ['pres' => $name, 'start' => "January 20, {$start}", 'end' => 'January 19, ' . ($start + 1), 'img' => 'x.jpg'];
            $terms[1001 + 2 * $i] = ['pres' => $name, 'start' => 'January 20, ' . ($start + 2), 'end' => 'January 19, ' . ($start + 3), 'img' => 'x.jpg'];
        }
        $compare = new CompareService($terms);
        $renderer = new CardRenderer('usdebt.bengusa.pro');

        // Six terms: the most rows the card draws, all of them real rows.
        $six = array_slice(array_keys($terms), 0, 12);
        $card = (new ShareCardBuilder($compare))->forCompare($source->load(), $six, true);
        self::assertNotNull($card);
        self::assertCount(6, $card->rows);
        self::assertPngBytes($renderer->render($card));
        self::assertCardFits($renderer->lastLayout(), 6, false);
        foreach (self::boxes($renderer->lastLayout(), 'row-label') as $label) {
            self::assertStringEndsWith("\u{2026}", $label['text'], 'a name too long for its column is cut, not drawn over the bar');
        }

        // Ten terms: five rows, then "and 5 more on the site".
        $card = (new ShareCardBuilder($compare))->forCompare($source->load(), array_keys($terms), true);
        self::assertNotNull($card);
        self::assertPngBytes($renderer->render($card));
        $layout = $renderer->lastLayout();
        self::assertCardFits($layout, 5, true);
        self::assertSame([ShareText::moreRows(5)], array_column(self::boxes($layout, 'row-more'), 'text'));

        // The real longest label fits whole, with no cut.
        $all = array_keys(PresidentService::getPresidentConfig());
        $card = (new ShareCardBuilder(new CompareService()))->forCompare($source->load(), $all, true);
        self::assertNotNull($card);
        $renderer->render($card);
        $labels = array_column(self::boxes($renderer->lastLayout(), 'row-label'), 'text');
        self::assertContains('Donald Trump (2017-2021, 2025-2029)', $labels);
        self::assertCardFits($renderer->lastLayout(), count($card->rows), false);

        // Through card.php too.
        $cards = new CardController($source, $compare, $renderer, null);
        self::assertPng($cards->handle(['compare' => implode(',', array_keys($terms))]));
    }

    // ---------- Your Share cards (per person, per household) ----------

    public function testCardForPerPersonAndPerHouseholdViews(): void
    {
        self::requireGd();
        $dir = $this->makeTempDir() . '/cards';
        $cards = $this->cards(null, $dir);
        $range = ['start_date' => '2009-01-20', 'end_date' => '2017-01-19'];
        $all = implode(',', array_keys(PresidentService::getPresidentConfig()));

        foreach (['person', 'household'] as $per) {
            self::assertPng($cards->handle($range + ['per' => $per]));
            self::assertPng($cards->handle($range + ['per' => $per, 'trend' => '0']));
            self::assertPng($cards->handle(['start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'per' => $per]), 'one day');
            self::assertPng($cards->handle(['compare' => $all, 'per' => $per]));
        }
        self::assertCount(8, self::cardFiles($dir), 'each view is its own cached card');
        self::assertPlainError(400, $cards->handle(['compare' => self::CLINTON, 'per' => 'person']));

        // Words and layout: the per words are drawn, the site name comes from its one place, and it all fits.
        $source = FrontControllerTest::source();
        $renderer = new CardRenderer('usdebt.bengusa.pro');
        $builder = new ShareCardBuilder(new CompareService());
        $card = $builder->forRange($source->load(), new DateTimeImmutable('2009-01-20'), new DateTimeImmutable('2017-01-19'), true, true, Per::Person);
        self::assertNotNull($card);
        self::assertMatchesRegularExpression('/^U\.S\. debt grew \$[0-9]{1,3}(,[0-9]{3})*\.[0-9]{2} per person$/', $card->headline, 'dollars, not trillions');
        self::assertStringContainsString('&per=person', $card->cardQuery);
        self::assertSame('us-debt-per-person-2009-01-26-to-2017-01-16.png', $card->downloadName);
        $renderer->render($card);
        $layout = $renderer->lastLayout();
        self::assertSame([SiteConfig::NAME], array_column(self::boxes($layout, 'site'), 'text'));
        self::assertSame([ShareText::perNote(Per::Person)], array_column(self::boxes($layout, 'note'), 'text'));
        self::assertContains(ShareText::SOURCE_PER, array_column(self::boxes($layout, 'footer'), 'text'));

        $compare = $builder->forCompare($source->load(), array_keys(PresidentService::getPresidentConfig()), true, Per::Household);
        self::assertNotNull($compare);
        self::assertSame('U.S. debt per household across presidential terms', $compare->headline);
        foreach ($compare->rows as $row) {
            self::assertLessThan(1_000_000, abs($row['change']), 'rows are per household, in dollars');
        }
        $renderer->render($compare);
        $compareLayout = $renderer->lastLayout();
        self::assertCardFits($compareLayout, count($compare->rows), false);
        // Every amount drawn on the card is in dollars ("+$29,101.12"), never trillions.
        foreach (self::boxes($compareLayout, 'row-value') as $value) {
            self::assertMatchesRegularExpression('/^[+-]?\$[0-9]{1,3}(,[0-9]{3})*\.[0-9]{2}$/', $value['text']);
        }
        self::assertContains(ShareText::SOURCE_PER, array_column(self::boxes($compareLayout, 'footer'), 'text'));
    }

    public function testPerShareWordsFollowTheFinalCopy(): void
    {
        $a = new DebtEntry('2009-01-20', 35_000.0);
        $b = new DebtEntry('2017-01-19', 61_000.0);
        $down = new DebtEntry('2017-01-19', 30_000.0);
        $same = new DebtEntry('2017-01-19', 35_000.0);
        $p = Per::Person;
        $h = Per::Household;

        // Share text, email.
        self::assertSame('U.S. debt grew $26,000.00 per person from Jan 20, 2009 to Jan 19, 2017.', ShareText::rangeShareText($a, $b, $p));
        self::assertSame('U.S. debt fell $5,000.00 per household from Jan 20, 2009 to Jan 19, 2017.', ShareText::rangeShareText($a, $down, $h));
        self::assertSame('U.S. debt was about the same per person from Jan 20, 2009 to Jan 19, 2017.', ShareText::rangeShareText($a, $same, $p));
        self::assertSame('U.S. debt on Jan 20, 2009: $35,000.00 per person.', ShareText::rangeShareText($a, $a, $p));
        self::assertSame('U.S. debt per person, Jan 20, 2009 to Jan 19, 2017', ShareText::rangeEmailSubject($a, $b, $p));
        self::assertSame('U.S. debt per household, Jan 20, 2009', ShareText::rangeEmailSubject($a, $a, $h));
        self::assertSame('Data: U.S. Treasury, Debt to the Penny; U.S. Census Bureau.', ShareText::emailNote($p));

        // Picture card.
        self::assertSame('U.S. debt grew $26,000.00 per person', ShareText::rangeHeadline($a, $b, $p));
        self::assertSame('U.S. debt fell $5,000.00 per household', ShareText::rangeHeadline($a, $down, $h));
        self::assertSame('U.S. debt was about the same per person', ShareText::rangeHeadline($a, $same, $p));
        self::assertSame('U.S. debt per person: $35,000.00', ShareText::rangeHeadline($a, $a, $p));
        self::assertSame('Per person: debt divided by U.S. population', ShareText::perNote($p));
        self::assertSame('Per household: debt divided by U.S. households', ShareText::perNote($h));
        self::assertSame('Source: U.S. Treasury, Census Bureau', ShareText::source($p));
        self::assertSame('Chart of U.S. debt per person from Jan 20, 2009 to Jan 19, 2017. Debt grew $26,000.00 per person.', ShareText::rangeAlt($a, $b, $p));
        self::assertSame('Chart of U.S. debt per person from Jan 20, 2009 to Jan 19, 2017. Debt was about the same per person.', ShareText::rangeAlt($a, $same, $p));

        // Link preview.
        self::assertSame('U.S. debt per person, Jan 20, 2009 to Jan 19, 2017', ShareText::rangeTitle($a, $b, $p));
        self::assertSame('Debt grew $26,000.00 per person over this period. Daily U.S. Treasury data and Census Bureau counts.', ShareText::rangeDescription($a, $b, $p));
        self::assertSame('Debt was about the same per household over this period. Daily U.S. Treasury data and Census Bureau counts.', ShareText::rangeDescription($a, $same, $h));
        self::assertSame('U.S. debt per person on Jan 20, 2009', ShareText::rangeTitle($a, $a, $p));
        self::assertSame('Debt was $35,000.00 per person on this day. Daily U.S. Treasury data and Census Bureau counts.', ShareText::rangeDescription($a, $a, $p));

        // The total words are unchanged.
        self::assertSame('U.S. debt grew $26,000.00', ShareText::rangeHeadline($a, $b));
        self::assertSame('U.S. debt, Jan 20, 2009', ShareText::rangeTitle($a, $a));
        self::assertSame('U.S. debt, Jan 20, 2009', ShareText::rangeEmailSubject($a, $a));
        self::assertSame('U.S. debt on Jan 20, 2009: $35,000.00.', ShareText::rangeShareText($a, $a));
        self::assertSame('Debt grew $26,000.00 over this period. Daily U.S. Treasury data.', ShareText::rangeDescription($a, $b));
        self::assertSame('Chart of U.S. debt from Jan 20, 2009 to Jan 19, 2017. Debt grew $26,000.00.', ShareText::rangeAlt($a, $b));
        self::assertSame(ShareText::SOURCE, ShareText::source(Per::Total));
        self::assertSame(ShareText::EMAIL_NOTE, ShareText::emailNote(Per::Total));
    }

    public function testPerShareWordsStayNeutral(): void
    {
        $a = new DebtEntry('2009-01-20', 35_000.0);
        $b = new DebtEntry('2017-01-19', 61_000.0);
        foreach ([Per::Person, Per::Household] as $per) {
            $words = [ShareText::rangeHeadline($a, $b, $per), ShareText::rangeTitle($a, $b, $per), ShareText::rangeDescription($a, $b, $per),
                ShareText::rangeAlt($a, $b, $per), ShareText::rangeShareText($a, $b, $per), ShareText::compareTitle($per),
                ShareText::compareDescription($per), ShareText::compareShareText($per), ShareText::compareAlt($per),
                ShareText::compareNote($per), ShareText::perNote($per), ShareText::method($per, null),
                ShareText::perHelp($per), ShareText::censusFootnote($per, 2025), ShareText::resultsHeading($per), ShareText::chartLabel($per)];
            foreach (['today', 'change', 'per_day', 'stats_start', 'stats_end', 'stats_change', 'stats_per_day', 'stats_above', 'stats_below', 'axis', 'compare_axis'] as $key) {
                $words[] = ShareText::label($per, $key);
            }
            foreach ($words as $text) {
                self::assertDoesNotMatchRegularExpression('/\b(under|because|thanks to|blame|record|surge|crisis|Democrat|Republican|owes?|your|burden|bill)\b/i', $text);
            }
        }
        self::assertSame('Show numbers as', ShareText::PER_SWITCH_LABEL, 'not "Your share": it could read as a personal bill');
        self::assertSame('U.S. debt grew $26,000.00 per person from Jan 20, 2009 to Jan 19, 2017.', ShareText::rangeShareText($a, $b, Per::Person));
        self::assertSame('Change in debt per household during each term, 1993 to 2026', ShareText::compareDetail(1993, 2026, Per::Household));
        // The total words are unchanged.
        self::assertSame('U.S. debt grew $26,000.00 from Jan 20, 2009 to Jan 19, 2017.', ShareText::rangeShareText($a, $b));
        self::assertSame('Change in debt during each term, 1993 to 2026', ShareText::compareDetail(1993, 2026));
    }

    /**
     * @param list<array{role: string, text: string, left: int, top: int, right: int, bottom: int}> $layout
     * @return list<array{role: string, text: string, left: int, top: int, right: int, bottom: int}>
     */
    private static function boxes(array $layout, string $role): array
    {
        return array_values(array_filter($layout, static fn (array $b): bool => $b['role'] === $role));
    }

    /**
     * Nothing leaves the card, labels stay left of their bars and amounts right
     * of them, rows do not overlap, and the lowest row ends above the footer
     * line.
     *
     * @param list<array{role: string, text: string, left: int, top: int, right: int, bottom: int}> $layout
     */
    private static function assertCardFits(array $layout, int $rows, bool $moreRow): void
    {
        foreach ($layout as $b) {
            $what = "{$b['role']} \"{$b['text']}\"";
            self::assertGreaterThanOrEqual(0, $b['left'], $what);
            self::assertGreaterThanOrEqual(0, $b['top'], $what);
            self::assertLessThanOrEqual(CardRenderer::WIDTH, $b['right'], $what);
            self::assertLessThanOrEqual(CardRenderer::HEIGHT, $b['bottom'], $what);
        }
        $rule = self::boxes($layout, 'footer-rule');
        self::assertCount(1, $rule);
        $ruleY = $rule[0]['top'];
        $labels = self::boxes($layout, 'row-label');
        $values = self::boxes($layout, 'row-value');
        $bars = self::boxes($layout, 'bar');
        self::assertCount($rows, $labels);
        self::assertCount($rows, $values);
        self::assertCount($rows, $bars);
        self::assertCount($moreRow ? 1 : 0, self::boxes($layout, 'row-more'));

        $words = array_merge(self::boxes($layout, 'site'), self::boxes($layout, 'headline'), self::boxes($layout, 'detail'), self::boxes($layout, 'note'));
        $wordsBottom = max(array_column($words, 'bottom'));
        for ($i = 0; $i < $rows; $i++) {
            self::assertLessThan($bars[$i]['left'], $labels[$i]['right'], "label {$i} runs into its bar: {$labels[$i]['text']}");
            self::assertGreaterThan($bars[$i]['right'], $values[$i]['left'], "amount {$i} runs into its bar");
            if ($i > 0) {
                $above = max($labels[$i - 1]['bottom'], $values[$i - 1]['bottom'], $bars[$i - 1]['bottom']);
                $top = min($labels[$i]['top'], $values[$i]['top'], $bars[$i]['top']);
                self::assertLessThan($top, $above, "row {$i} overlaps the row above");
            }
        }
        $body = array_merge($labels, $values, $bars, self::boxes($layout, 'row-more'));
        self::assertGreaterThan($wordsBottom, min(array_column($body, 'top')), 'the first row starts below the words');
        self::assertLessThan($ruleY, max(array_column($body, 'bottom')), 'the lowest row ends above the footer line');
        foreach (self::boxes($layout, 'footer') as $f) {
            self::assertGreaterThan($ruleY, $f['top'], 'the footer sits below its line');
        }
    }

    private static function assertPngBytes(string $png): void
    {
        $info = getimagesizefromstring($png);
        self::assertIsArray($info);
        self::assertSame([1200, 630, 'image/png'], [$info[0], $info[1], $info['mime']]);
    }

    public function testCardRejectsInputThePageRejectsWith400AndNoImage(): void
    {
        $cards = $this->cards();
        foreach ([
            'bad date' => ['start_date' => '2026-02-30'],
            'script in date' => ['start_date' => '"><script>'],
            'reversed' => ['start_date' => '2026-11-01', 'end_date' => '2026-10-01'],
            'one pick' => ['compare' => self::CLINTON],
            'no valid picks' => ['compare' => 'bogus,also-bogus'],
        ] as $label => $query) {
            $response = $cards->handle($query);
            self::assertPlainError(400, $response);
            self::assertNotSame('', trim($response->body), $label);
        }
    }

    public function testCardForARangeWithNoDataIs400(): void
    {
        self::requireGd();
        $response = $this->cards()->handle(['start_date' => '1980-01-01', 'end_date' => '1980-02-01']);

        self::assertPlainError(400, $response);
        self::assertSame(ShareText::CARD_NO_DATA . "\n", $response->body);
    }

    public function testCardWithNoTreasuryDataIs503(): void
    {
        $down = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new DataUnavailableException();
            }
        };

        self::assertPlainError(503, $this->cards($down)->handle(['start_date' => '2026-01-01', 'end_date' => '2026-11-09']));
    }

    public function testCardIs503WhenThisServerCannotDrawIt(): void
    {
        // No gd, or a missing font: the same answer either way.
        $cards = new CardController(
            FrontControllerTest::source(),
            new CompareService(),
            new CardRenderer('usdebt.bengusa.pro', $this->makeTempDir() . '/no-fonts-here'),
            null,
        );
        $response = $cards->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09']);

        self::assertPlainError(503, $response);
        self::assertSame(ShareText::CARD_UNAVAILABLE . "\n", $response->body);
    }

    public function testUnexpectedCardErrorIs500AndLeaksNothing(): void
    {
        self::requireGd();
        $broken = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new Error('secret /var/www detail');
            }
        };
        $response = $this->cards($broken)->handle([]);

        self::assertPlainError(500, $response);
        self::assertStringNotContainsString('secret', $response->body);
    }

    public function testCardUsesTheSavedDailyCopyAndNeverCallsTheTreasury(): void
    {
        self::requireGd();
        $dir = $this->makeTempDir();
        $today = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
        file_put_contents($dir . '/' . DebtRepository::CACHE_FILE, json_encode([
            'version' => 1,
            'saved_on' => $today,
            'rows' => [['2026-10-01', 37.9e12], ['2026-10-15', 38.0e12], ['2026-11-02', 38.2e12]],
        ]));
        $fetcher = new FakeHttpFetcher(FakeHttpFetcher::body([]));
        $repo = new DebtRepository(new FiscalDataClient($fetcher), $dir, null, null, DebtRepository::RETRY_AFTER_SECONDS, 0.0, null, $dir . '/markers');

        self::assertPng($this->cards($repo, $dir . '/cards')->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-02']));
        self::assertSame([], $fetcher->calls, 'no Treasury call');
    }

    // ---------- Card cache ----------

    public function testSameViewGivesTheSameCachedCard(): void
    {
        self::requireGd();
        $dir = $this->makeTempDir() . '/cards';
        $cards = $this->cards(null, $dir);

        // Weekly test data: these ranges cover the same reported days.
        $first = $cards->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09']);
        self::assertPng($first);
        $files = self::cardFiles($dir);
        self::assertCount(1, $files);

        // Swap the saved card for a marker: the next request must be served from the file.
        file_put_contents($files[0], self::PNG_SIGNATURE . 'cached');
        $again = $cards->handle(['start_date' => '2026-10-02', 'end_date' => '2026-11-10']);
        self::assertSame(self::PNG_SIGNATURE . 'cached', $again->body);
        self::assertCount(1, self::cardFiles($dir));

        // The trend switch changes a range card...
        $cards->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09', 'trend' => '0']);
        self::assertCount(2, self::cardFiles($dir));
        // ...but not a one-day card, which has no trend line.
        $cards->handle(['start_date' => '2026-11-09', 'end_date' => '2026-11-09']);
        $cards->handle(['start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'trend' => '0']);
        self::assertCount(3, self::cardFiles($dir));

        // Compare: order, duplicates and the trend switch do not matter.
        $cards->handle(['compare' => self::BUSH . ',' . self::CLINTON . ',' . self::BUSH]);
        $cards->handle(['compare' => self::CLINTON . ',' . self::BUSH, 'trend' => '0']);
        self::assertCount(4, self::cardFiles($dir), 'one more for Compare');
    }

    public function testCacheKeyChangesWithNewDataAndTheView(): void
    {
        $builder = new ShareCardBuilder(new CompareService());
        $entries = [new DebtEntry('2026-11-02', 38.0e12), new DebtEntry('2026-11-09', 38.1e12)];
        $start = new DateTimeImmutable('2026-11-01');
        $end = new DateTimeImmutable('2026-11-10');

        $today = $builder->forRange(new DebtDataset($entries, '2026-11-12'), $start, $end, true, true);
        $same = $builder->forRange(new DebtDataset($entries, '2026-11-12'), new DateTimeImmutable('2026-11-02'), new DateTimeImmutable('2026-11-09'), true, true);
        $tomorrow = $builder->forRange(new DebtDataset($entries, '2026-11-13'), $start, $end, true, true);

        self::assertSame('start_date=2026-11-02&end_date=2026-11-09', $today->cardQuery);
        self::assertSame('start_date=2026-11-01&end_date=2026-11-10', $today->pageQuery, 'the page link keeps the dates as picked');
        self::assertSame($today->cacheKey, $same->cacheKey);
        self::assertNotSame($today->cacheKey, $tomorrow->cacheKey, 'redrawn when new data is saved');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $today->cacheKey);
        self::assertNull($builder->forRange(new DebtDataset($entries, '2026-11-12'), new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2020-02-01'), true, true));
    }

    public function testCardCacheIsBoundedAndDropsTheOldestFirst(): void
    {
        $dir = $this->makeTempDir() . '/cards';
        $cache = $this->cardCache($dir, 3);
        $keys = [];
        for ($i = 0; $i < 5; $i++) {
            $keys[$i] = hash('sha256', (string) $i);
            self::assertTrue($cache->put($keys[$i], "png{$i}"));
            touch($dir . '/' . $keys[$i] . '.png', 1_700_000_000 + $i);
        }
        $cache->trim();

        self::assertCount(3, self::cardFiles($dir));
        self::assertNull($cache->get($keys[0]));
        self::assertNull($cache->get($keys[1]));
        self::assertSame('png4', $cache->get($keys[4]));

        // A crashed save leaves a temp file; an old one is cleaned up, a fresh one is left alone.
        file_put_contents($dir . '/old.tmp', 'x');
        touch($dir . '/old.tmp', time() - 7200);
        file_put_contents($dir . '/fresh.tmp', 'x');
        $cache->trim();
        self::assertFileDoesNotExist($dir . '/old.tmp');
        self::assertFileExists($dir . '/fresh.tmp');
    }

    public function testCardCacheOnlyAcceptsHashKeys(): void
    {
        $dir = $this->makeTempDir() . '/cards';
        $cache = $this->cardCache($dir);

        self::assertFalse($cache->put('../../escape', 'x'));
        self::assertNull($cache->get('../../escape'));
        self::assertFalse($cache->put(str_repeat('A', 64), 'x'), 'lower-case hex only');
        self::assertSame([], glob(dirname($dir) . '/*.png') ?: []);
    }

    public function testCardStillWorksWhenTheCacheFolderCannotBeUsed(): void
    {
        self::requireGd();
        $file = $this->makeTempDir() . '/not-a-folder';
        file_put_contents($file, 'x');

        self::assertPng($this->cards(null, $file)->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09']));
    }

    public function testCardCacheProblemIsLoggedOncePerWindowAcrossProcesses(): void
    {
        $tmp = $this->makeTempDir();
        $blocked = $tmp . '/not-a-folder';
        file_put_contents($blocked, 'x');
        $now = 1_800_000_000;
        // Each call is a new request: a new cache, a new LogOnce, the same marker folder.
        $cache = static function () use ($blocked, $tmp, &$now): CardCache {
            return new CardCache($blocked, new LogOnce($tmp . '/log-markers', 900, static function () use (&$now): int {
                return $now;
            }));
        };
        $log = $tmp . '/php-error.log';
        $count = fn (): int => is_file($log) ? substr_count((string) file_get_contents($log), 'share card folder') : 0;
        $forget = static fn () => (new \ReflectionProperty(LogOnce::class, 'logged'))->setValue(null, []);
        $oldLog = ini_set('error_log', $log);
        try {
            $key = hash('sha256', 'x');
            self::assertFalse($cache()->put($key, 'png'));
            self::assertFalse($cache()->put($key, 'png'));
            self::assertSame(1, $count(), 'two requests, one log line');

            // A new PHP-FPM request starts with empty static state; the marker file still holds.
            $forget();
            $now += 300;
            self::assertFalse($cache()->put($key, 'png'));
            self::assertSame(1, $count(), 'still inside the window in a new process');

            $forget();
            $now += 700;
            self::assertFalse($cache()->put($key, 'png'));
            self::assertSame(2, $count(), 'logged again once the window has passed');
        } finally {
            ini_set('error_log', (string) $oldLog);
        }
    }

    public function testCardFileAlreadyRemovedByAnotherRequestIsNotAFailure(): void
    {
        $dir = $this->makeTempDir() . '/cards';
        $log = dirname($dir) . '/php-error.log';
        $oldLog = ini_set('error_log', $log);
        try {
            // Another request's trim removed it between our listing and our delete.
            self::assertTrue(CardCache::remove($dir . '/' . hash('sha256', 'gone') . '.png'));
            $file = $dir . '-file.png';
            file_put_contents($file, 'x');
            self::assertTrue(CardCache::remove($file));
            self::assertFileDoesNotExist($file);
            // A file that is still there after the delete is a real failure.
            mkdir($dir . '-folder.png');
            self::assertFalse(CardCache::remove($dir . '-folder.png'));

            // A trim over a folder that another request is trimming too logs nothing.
            $cache = $this->cardCache($dir, 1);
            self::assertTrue($cache->put(hash('sha256', 'a'), 'a'));
            self::assertTrue($cache->put(hash('sha256', 'b'), 'b'));
            self::assertCount(1, self::cardFiles($dir));
        } finally {
            ini_set('error_log', (string) $oldLog);
        }
        self::assertFalse(is_file($log) && str_contains((string) file_get_contents($log), 'could not remove'), 'no false warning');
    }

    public function testCardLinksInTheMenuAreRootRelative(): void
    {
        // Same for any page address (/, /index.php, /index.php/extra): the link starts at the site root.
        self::assertSame('/card.php?compare=1,2', (new ShareLinks())->localCard('compare=1,2'));
        self::assertSame('/card.php', (new ShareLinks())->localCard(''));
        // A site in a sub-folder keeps its folder.
        self::assertSame('/usdebt/card.php?trend=0', (new ShareLinks('https://example.test/usdebt/'))->localCard('trend=0'));

        $share = self::shareMenu(self::page(['start_date' => '2009-01-20', 'end_date' => '2017-01-19'])->body);
        self::assertStringStartsWith('/card.php?', $share['download']['href']);
        self::assertStringStartsWith('/card.php?', $share['thumb']['src']);
        self::assertStringStartsWith('/card.php?', $share['attrs']['data-card']);
    }

    public function testShareMenuAndScriptHoldNoShareWordsOfTheirOwn(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        // The menu view: with every PHP block and tag taken out, no words are left.
        $view = (string) file_get_contents($root . '/lib/View/share_menu.php');
        $text = trim((string) preg_replace(['#<\?php.*?\?>#s', '#<[^>]+>#'], ' ', $view));
        self::assertSame('', $text, 'share_menu.php should take every word from ShareText');

        // The page script takes the copy messages from the menu, which takes them from ShareText.
        $js = (string) file_get_contents($root . '/Public/js/usdebt.js');
        foreach ([ShareText::MENU_COPIED, ShareText::MENU_COPY_FAILED, "Couldn\\'t copy"] as $words) {
            self::assertStringNotContainsString($words, $js);
        }
        $html = self::page(['start_date' => '2009-01-20', 'end_date' => '2017-01-19'])->body;
        $attrs = self::shareMenu($html)['attrs'];
        self::assertSame(ShareText::MENU_COPIED, $attrs['data-copied']);
        self::assertSame(ShareText::MENU_COPY_FAILED, $attrs['data-copy-failed']);

        // The card's error lines live in ShareText too.
        $controller = (string) file_get_contents($root . '/lib/Controller/CardController.php');
        self::assertStringNotContainsString(ShareText::CARD_NO_DATA, $controller);
        self::assertStringNotContainsString(ShareText::CARD_UNAVAILABLE, $controller);
    }

    // ---------- Font ----------

    public function testCardFontIsSelfHostedWithItsLicenceAndPinned(): void
    {
        $dir = dirname(__DIR__, 2) . '/src/lib/Assets/fonts';
        foreach (self::FONT_SHA256 as $file => $sha) {
            self::assertSame($sha, hash_file('sha256', $dir . '/' . $file), $file);
        }
        $licence = (string) file_get_contents($dir . '/DejaVu-LICENSE.txt');
        self::assertStringContainsString('Bitstream Vera Fonts Copyright', $licence);
        self::assertStringContainsString('DejaVu changes are in public domain', $licence);
    }
}
