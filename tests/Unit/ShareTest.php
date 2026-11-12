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
use USDebt\Service\CardCache;
use USDebt\Service\CardRenderer;
use USDebt\Service\CompareService;
use USDebt\Service\DebtRepository;
use USDebt\Service\DebtSource;
use USDebt\Service\FiscalDataClient;
use USDebt\Service\PresidentService;
use USDebt\Service\ShareCardBuilder;
use USDebt\Service\ShareLinks;
use USDebt\Service\ShareText;
use USDebt\Tests\Support\FakeHttpFetcher;
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
            new CardCache($cacheDir ?? $this->makeTempDir() . '/cards'),
        );
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
        self::assertStringStartsWith('U.S. debt grew $', $meta['og:title']);
        self::assertStringContainsString(ShareText::SOURCE, $meta['og:description']);
        self::assertSame(self::BASE . '/?start_date=2020-01-01&end_date=2026-11-09', $meta['og:url']);
        // The card link uses the first and last day that has data.
        self::assertSame(self::BASE . "/card.php?start_date={$first}&end_date={$last}", $meta['og:image']);
        self::assertSame(['1200', '630', 'image/png'], [$meta['og:image:width'], $meta['og:image:height'], $meta['og:image:type']]);
        self::assertSame('summary_large_image', $meta['twitter:card']);
        self::assertSame($meta['og:title'], $meta['twitter:title']);
        self::assertSame($meta['og:image'], $meta['twitter:image']);
        self::assertStringStartsWith($meta['og:title'], $meta['og:image:alt']);
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
        self::assertSame(ShareText::COMPARE_TITLE, $meta['og:title']);
        self::assertStringContainsString('Bill Clinton (1993-2001): +$', $meta['og:description']);
        self::assertStringContainsString(ShareText::COMPARE_CAPTION, $meta['og:description']);
    }

    public function testErrorPageGetsTheSiteWidePreview(): void
    {
        $meta = self::meta(self::page(['start_date' => '2026-02-30'])->body);

        self::assertSame(ShareText::SITE_NAME, $meta['og:title']);
        self::assertSame(ShareText::SITE_DESCRIPTION, $meta['og:description']);
        self::assertSame(self::BASE . '/', $meta['og:url']);
        self::assertSame(self::BASE . '/card.php', $meta['og:image']);
        self::assertSame('summary_large_image', $meta['twitter:card']);
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

        self::assertSame('U.S. debt grew $9.32 trillion from Jan 20, 2009 to Jan 19, 2017', ShareText::rangeHeadline($a, $b));
        self::assertSame('U.S. debt fell $9.32 trillion from Jan 19, 2017 to Jan 20, 2009', ShareText::rangeHeadline(
            new DebtEntry('2017-01-19', 19.95e12),
            new DebtEntry('2009-01-20', 10.63e12),
        ));
        self::assertSame('U.S. debt did not change from Jan 20, 2009 to Jan 19, 2017', ShareText::rangeHeadline($a, new DebtEntry('2017-01-19', 10.63e12)));
        self::assertSame('U.S. debt was $10.63 trillion on Jan 20, 2009', ShareText::rangeHeadline($a, $a));
        self::assertSame('From $10.63 trillion to $19.95 trillion.', ShareText::rangeDetail($a, $b));
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
        // Ten terms with data: more rows than fit, so the last row counts the rest.
        $terms = [];
        for ($i = 0; $i < 10; $i++) {
            $start = 1993 + 3 * $i;
            $terms[1000 + $i] = ['pres' => "President number {$i} with a long name", 'start' => "January 20, {$start}", 'end' => 'January 19, ' . ($start + 3), 'img' => 'x.jpg'];
        }
        $cards = new CardController(FrontControllerTest::source(), new CompareService($terms), new CardRenderer('usdebt.bengusa.pro'), null);

        self::assertPng($cards->handle(['compare' => implode(',', array_keys($terms))]));
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
        self::assertSame(CardController::NO_DATA . "\n", $response->body);
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
        self::assertSame(CardController::UNAVAILABLE . "\n", $response->body);
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
        $cache = new CardCache($dir, 3);
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
        $cache = new CardCache($dir);

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
