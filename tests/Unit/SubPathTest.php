<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use USDebt\Controller\FrontController;
use USDebt\Controller\USDebtController;
use USDebt\Model\Per;
use USDebt\Service\CompareService;
use USDebt\Service\PresidentService;
use USDebt\Service\ShareLinks;

/**
 * The site is served at https://bengusa.pro/ourusdebt/ (the web server strips
 * "/ourusdebt" before PHP runs). Every link on every kind of page must stay
 * under that folder, and every absolute link must use that address.
 */
final class SubPathTest extends TestCase
{
    private const ORIGIN = 'https://bengusa.pro';
    private const BASE = 'https://bengusa.pro/ourusdebt';
    /** The address the browser shows: the folder with its trailing slash. */
    private const PAGE = 'https://bengusa.pro/ourusdebt/';

    protected function setUp(): void
    {
        putenv(ShareLinks::ENV_BASE_URL . '=' . self::BASE);
    }

    protected function tearDown(): void
    {
        putenv(ShareLinks::ENV_BASE_URL);
    }

    private static function app(): FrontController
    {
        return new FrontController(new USDebtController(
            FrontControllerTest::source(),
            new CompareService(),
            ShareLinks::fromEnvironment(),
        ));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function pages(): array
    {
        $all = array_map('strval', array_keys(PresidentService::getPresidentConfig()));
        return [
            'home' => [[], []],
            'date range' => [['start_date' => '2020-01-01', 'end_date' => '2026-11-09'], []],
            'date range, trend off' => [['start_date' => '2020-01-01', 'end_date' => '2026-11-09', 'trend' => '0'], []],
            'per person' => [['per' => 'person'], []],
            'compare (form post)' => [[], ['submit' => 'Compare', 'compare_pres' => $all]],
            'compare (link)' => [['compare' => implode(',', array_slice($all, 0, 2)), 'per' => 'household'], []],
            'bad date (error page)' => [['start_date' => '2026-02-30'], []],
        ];
    }

    /**
     * Resolves a link the way a browser does against the page address.
     * Returns the absolute URL.
     */
    private static function resolve(string $link): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $link)) {
            return $link;
        }
        if (str_starts_with($link, '//')) {
            return 'https:' . $link;
        }
        if (str_starts_with($link, '/')) {
            return self::ORIGIN . $link;
        }
        if ($link === '' || $link[0] === '#') {
            return self::PAGE . $link;
        }
        if ($link[0] === '?') {
            return self::PAGE . $link;
        }
        // Relative path: resolve against /ourusdebt/, folding "./" and "../".
        $parts = ['ourusdebt'];
        $rest = preg_split('#([?\#])#', $link, 2, PREG_SPLIT_DELIM_CAPTURE);
        $path = $rest[0];
        $tail = isset($rest[1]) ? $rest[1] . ($rest[2] ?? '') : '';
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        $endsWithSlash = str_ends_with($path, '/') || str_ends_with($path, '/.') || $path === '.';
        return self::ORIGIN . '/' . implode('/', $parts) . ($endsWithSlash ? '/' : '') . $tail;
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}> The pages that have a Share menu (not the error page). */
    public static function pagesWithShareMenu(): array
    {
        return array_filter(self::pages(), static fn (string $name): bool => !str_contains($name, 'error page'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return list<string> Every href, src and form action on the page, decoded.
     */
    private static function links(string $html): array
    {
        preg_match_all('#\b(?:href|src|action)="([^"]*)"#', $html, $m);
        return array_map(static fn (string $v): string => html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    #[DataProvider('pages')]
    public function testEveryLinkOnThePageStaysUnderOurUsDebt(array $query, array $post): void
    {
        $html = self::app()->handle($query, $post)->body;
        $links = self::links($html);
        self::assertNotEmpty($links);

        $checked = 0;
        $escapes = [];
        foreach ($links as $link) {
            if (str_starts_with($link, 'mailto:')) {
                continue;
            }
            $url = self::resolve($link);
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host !== 'bengusa.pro') {
                // Another site (Treasury, a social site): fine, and never ours.
                self::assertMatchesRegularExpression('#^https://#', $url, "external link must be https: {$link}");
                continue;
            }
            $checked++;
            if (!preg_match('#^https://bengusa\.pro/ourusdebt(/|\?|$)#', $url)) {
                $escapes[] = $link . ' -> ' . $url;
            }
        }
        self::assertGreaterThan(0, $checked, 'the page has links to its own site');
        self::assertSame([], $escapes, 'links that leave /ourusdebt/');
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    #[DataProvider('pagesWithShareMenu')]
    public function testShareTagsAndLinksUseTheRealAddress(array $query, array $post): void
    {
        $html = self::app()->handle($query, $post)->body;
        self::assertStringContainsString('id="share_url"', $html);
        preg_match_all('#<meta (?:property|name)="((?:og|twitter):[a-z:_]+)" content="([^"]*)">#', $html, $m, PREG_SET_ORDER);
        $meta = [];
        foreach ($m as [, $name, $content]) {
            $meta[$name] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        self::assertStringStartsWith(self::BASE . '/', $meta['og:url']);
        self::assertStringStartsWith(self::BASE . '/card.php', $meta['og:image']);
        if (isset($meta['twitter:image'])) {
            self::assertStringStartsWith(self::BASE . '/card.php', $meta['twitter:image']);
        }

        // The share field and the network links carry the same page address.
        self::assertStringContainsString('id="share_url" value="' . htmlspecialchars($meta['og:url'], ENT_QUOTES) . '"', $html);
        $encoded = rawurlencode(self::BASE . '/');
        self::assertStringContainsString($encoded, $html, 'network links carry the encoded /ourusdebt/ address');
        self::assertStringNotContainsString('usdebt.bengusa.pro', $html);
        // Any encoded root link (bare, or followed by anything) would be a page link without /ourusdebt.
        $root = rawurlencode('https://bengusa.pro/');
        self::assertDoesNotMatchRegularExpression('/' . preg_quote($root, '/') . '(?!ourusdebt)/', $html, 'no page link without /ourusdebt');
    }

    public function testTheCardLinkInTheMenuIsUnderOurUsDebt(): void
    {
        $html = self::app()->handle(['compare' => '727506000,979966800'], [])->body;
        self::assertStringContainsString('src="/ourusdebt/card.php?compare=727506000,979966800"', $html);
        self::assertStringContainsString('href="/ourusdebt/card.php?compare=727506000,979966800"', $html);
    }

    public function testUnsetUsesTheDefaultAddressAndLocalDevelopmentSetsItToTheRoot(): void
    {
        putenv(ShareLinks::ENV_BASE_URL);
        $links = ShareLinks::fromEnvironment();
        self::assertSame(ShareLinks::DEFAULT_BASE_URL, $links->baseUrl());
        self::assertSame(self::BASE, ShareLinks::DEFAULT_BASE_URL, 'the default is the real address');
        self::assertSame('/ourusdebt/card.php?per=person', $links->localCard('per=person'));

        // An empty value is the same as unset.
        putenv(ShareLinks::ENV_BASE_URL . '=');
        self::assertSame('/ourusdebt/card.php', ShareLinks::fromEnvironment()->localCard(''));

        // A local address with no folder keeps everything at "/".
        putenv(ShareLinks::ENV_BASE_URL . '=http://localhost:8000');
        $local = ShareLinks::fromEnvironment();
        self::assertSame('http://localhost:8000/?trend=0', $local->page('trend=0'));
        self::assertSame('/card.php', $local->localCard(''));
    }

    public function testTheFolderAddressBuildsEveryLinkUnderTheFolder(): void
    {
        $links = ShareLinks::fromEnvironment();
        self::assertSame(self::BASE, $links->baseUrl());
        self::assertSame(self::BASE . '/', $links->page(''));
        self::assertSame(self::BASE . '/?trend=0', $links->page('trend=0'));
        self::assertSame(self::BASE . '/card.php?trend=0', $links->card('trend=0'));
        self::assertSame('/ourusdebt/card.php?trend=0', $links->localCard('trend=0'));
    }
}
