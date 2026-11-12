<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use USDebt\Controller\FrontController;
use USDebt\Model\DebtDataset;
use USDebt\Model\DebtEntry;
use USDebt\Model\Per;
use USDebt\Model\RequestModel;
use USDebt\Service\ChartData;
use USDebt\Service\CompareService;
use USDebt\Service\PopulationService;
use USDebt\Service\StatsCalculator;
use USDebt\Service\TrendService;
use USDebt\SiteConfig;

/**
 * "Your Share": the debt per person and per household, the population data
 * behind it, the per= switch in every link, and the site name in one place.
 */
final class YourShareTest extends TestCase
{
    /**
     * Fixture: population 2003-07-01 = 100M, 2004-07-01 = 136.6M (366 days,
     * so +100,000 a day); households 2003-03-15 = 50M, 2004-03-15 = 50.366M
     * (366 days, so +1,000 a day).
     */
    private static function fixture(): PopulationService
    {
        return PopulationService::fromYears(
            [2003 => 100_000_000, 2004 => 136_600_000],
            [2003 => 50_000_000, 2004 => 50_366_000],
        );
    }

    private static function plus(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify("+{$days} days")->format('Y-m-d');
    }

    // ---------- Population data ----------

    public function testInterpolationIsExactOnAnchorDaysAndAtTheMidpoint(): void
    {
        $pop = self::fixture();
        self::assertSame(100_000_000.0, $pop->population('2003-07-01'), 'population anchor: July 1');
        self::assertSame(136_600_000.0, $pop->population('2004-07-01'));
        self::assertSame(118_300_000.0, $pop->population(self::plus('2003-07-01', 183)), 'midpoint');
        self::assertSame(101_000_000.0, $pop->population('2003-07-11'), '10 days in: +100,000 a day');

        self::assertSame(50_000_000.0, $pop->households('2003-03-15'), 'households anchor: March 15');
        self::assertSame(50_366_000.0, $pop->households('2004-03-15'));
        self::assertSame(50_183_000.0, $pop->households(self::plus('2003-03-15', 183)), 'midpoint');
        self::assertNull($pop->held(Per::Person, self::plus('2003-07-01', 183)));
    }

    public function testAfterTheLastYearTheLatestCensusFigureIsHeldNotProjected(): void
    {
        $pop = self::fixture();
        self::assertSame(136_600_000.0, $pop->population('2026-11-09'));
        self::assertSame(PopulationService::HELD_LATEST, $pop->held(Per::Person, '2004-07-02'));
        self::assertNull($pop->held(Per::Person, '2004-07-01'), 'the anchor day itself is a real figure');
        self::assertSame(['year' => 2004, 'date' => '2004-07-01', 'value' => 136_600_000.0], $pop->latest(Per::Person));
        self::assertSame(['year' => 2004, 'date' => '2004-03-15', 'value' => 50_366_000.0], $pop->latest(Per::Household));

        // Before the first anchor the first figure is held the same way.
        self::assertSame(100_000_000.0, $pop->population('2003-01-04'));
        self::assertSame(PopulationService::HELD_EARLIEST, $pop->held(Per::Person, '2003-01-04'));
        self::assertNull($pop->held(Per::Total, '2026-11-09'), 'the total view divides by nothing');
        self::assertNull($pop->latest(Per::Total));
    }

    public function testShippedCensusDataReadsBackExactly(): void
    {
        $pop = new PopulationService();
        self::assertSame(341_784_857.0, $pop->population('2025-07-01'));
        self::assertSame(331_578_104.0, $pop->population('2020-07-01'));
        self::assertSame(259_918_588.0, $pop->population('1993-01-04'), 'before July 1, 1993: the first figure');
        self::assertSame(134_790_000.0, $pop->households('2025-03-15'));
        self::assertSame(341_784_857.0, $pop->population('2026-11-09'), 'after the last year: held');
        self::assertSame(134_790_000.0, $pop->households('2026-11-09'));
        self::assertEqualsWithDelta(340_003_797 + (341_784_857 - 340_003_797) * 183 / 365, $pop->population('2024-12-31'), 1e-6);

        $data = json_decode((string) file_get_contents(PopulationService::defaultFile()), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(range(1993, 2025), array_map('intval', array_keys($data['population'])), 'no year missing');
        self::assertSame(range(1993, 2025), array_map('intval', array_keys($data['households'])));
        self::assertNotEmpty($data['source_notes']);
        self::assertNotEmpty($data['sources']);
    }

    public function testBadPopulationDataIsAnErrorNotAWrongNumber(): void
    {
        $this->expectException(\RuntimeException::class);
        PopulationService::fromYears([2003 => 0], [2003 => 1]);
    }

    // ---------- Scaling, change, trend ----------

    public function testScaleDividesEachDayByThatDaysPopulation(): void
    {
        $pop = self::fixture();
        $entries = [new DebtEntry('2003-07-01', 1.0e14), new DebtEntry('2003-07-11', 2.02e14)];

        $person = $pop->scale($entries, Per::Person);
        self::assertSame(1_000_000.0, $person[0]->totalDebt);
        self::assertSame(2_000_000.0, $person[1]->totalDebt, '2.02e14 / 101 million');
        self::assertSame(2_000_000.0, $pop->scale([new DebtEntry('2003-03-15', 1.0e14)], Per::Household)[0]->totalDebt);
        self::assertSame($entries, $pop->scale($entries, Per::Total), 'the total view is unchanged');
    }

    public function testChangePerPersonIsTheDifferenceOfTheDailyRatios(): void
    {
        // Start: $100T / 100M = $1,000,000 a person. End: $204.9T / 136.6M = $1,500,000.
        $entries = self::fixture()->scale([
            new DebtEntry('2003-07-01', 1.0e14),
            new DebtEntry('2004-07-01', 2.049e14),
        ], Per::Person);
        $stats = StatsCalculator::summarize($entries, Per::Person->unitDollars());

        self::assertEqualsWithDelta(500_000.0, $stats['delta'], 1e-6, 'D_end/P_end - D_start/P_start');
        self::assertNotEqualsWithDelta((2.049e14 - 1.0e14) / 136_600_000, $stats['delta'], 1.0, 'not the growth divided by today\'s people');
        self::assertEqualsWithDelta(round(500_000 / 366, 2), $stats['average_per_day'], 1e-9);
    }

    public function testTrendAndCurveAreFittedOnTheScaledSeries(): void
    {
        // Per person exactly 1000, 1010, 1020 (a straight line), while the
        // total itself is not a straight line (the population grows too).
        $pop = self::fixture();
        $raw = [];
        foreach ([0, 1, 2] as $i) {
            $date = self::plus('2003-07-01', $i);
            $raw[] = new DebtEntry($date, $pop->population($date) * (1000 + 10 * $i));
        }
        $entries = $pop->scale($raw, Per::Person);
        $chart = ChartData::daily($entries, Per::Person);

        self::assertSame('person', $chart['per']);
        self::assertSame(Per::UNIT_USD, $chart['unit']);
        $byKey = array_column($chart['series'], null, 'key');
        self::assertSame([['2003-07-01', 1000.0], ['2003-07-02', 1010.0], ['2003-07-03', 1020.0]], $byKey['debt']['points']);
        self::assertEqualsWithDelta(10.0, $byKey['trend']['slope_per_day'], 1e-9, 'dollars per person per day');
        self::assertEqualsWithDelta(1.0, $byKey['trend']['r2'], 1e-9);
        foreach ($byKey['trend']['points'] as $i => [$date, $value]) {
            self::assertSame($byKey['debt']['points'][$i][0], $date);
            self::assertEqualsWithDelta($byKey['debt']['points'][$i][1], $value, 1e-6, 'the line sits on the plotted data');
        }
        self::assertEqualsWithDelta(1030.0, $byKey['projection']['points'][0][1], 1e-6);
        self::assertArrayHasKey('curve', $byKey);
        self::assertEqualsWithDelta(1000.0, $byKey['curve']['points'][0][1], 1.0);
        self::assertSame(TrendService::growthPctPerYear($entries, 1.0) !== null, true);

        $stats = StatsCalculator::summarize($entries, 1.0);
        self::assertSame(10.0, $stats['trend_per_day']);

        // The total view keeps trillions, as before.
        $total = ChartData::daily($raw);
        self::assertSame(['total', Per::UNIT_TRILLION_USD], [$total['per'], $total['unit']]);
        self::assertEqualsWithDelta($raw[0]->totalDebt / 1e12, $total['series'][0]['points'][0][1], 1e-6);
        self::assertSame(1_000_000_000_000.0, ChartData::toDollars($total, 1.0));
        self::assertSame(1.0, ChartData::toDollars($chart, 1.0));
    }

    public function testCompareGivesTheChangePerPersonForEachTerm(): void
    {
        $terms = [
            100 => ['pres' => 'Alpha', 'start' => '2003-07-01', 'end' => '2003-07-11', 'img' => 'a.jpg'],
            200 => ['pres' => 'Bravo', 'start' => '2004-07-01', 'end' => '2004-07-05', 'img' => 'b.jpg'],
        ];
        $dataset = new DebtDataset([
            new DebtEntry('2003-07-01', 1.0e14),      // $1,000,000 a person
            new DebtEntry('2003-07-11', 2.02e14),     // $2,000,000 a person (101M people)
            new DebtEntry('2004-07-01', 1.366e14),    // $1,000,000 a person (held after the last anchor)
            new DebtEntry('2004-07-05', 1.4343e14),   // $1,050,000 a person
        ], '2026-11-12');
        $service = new CompareService($terms, self::fixture());

        $series = $service->buildSeries([100, 200], $dataset, Per::Person);
        self::assertEqualsWithDelta(1_000_000.0, $series[0]['change'], 1e-6);
        self::assertEqualsWithDelta(50_000.0, $series[1]['change'], 1e-6);
        self::assertSame([[1, 0.0]], $series[0]['data'], 'points in dollars, not trillions');

        $chart = ChartData::compare($series, Per::Person);
        self::assertSame(['compare', 'person', Per::UNIT_USD], [$chart['type'], $chart['per'], $chart['unit']]);

        $total = $service->buildSeries([100, 200], $dataset);
        self::assertEqualsWithDelta(1.02e14, $total[0]['change'], 1.0, 'the total is unchanged');
    }

    // ---------- per= in the request ----------

    /** @return array<string, array{0: mixed, 1: Per}> */
    public static function perInputs(): array
    {
        return [
            'person' => ['person', Per::Person],
            'household' => ['household', Per::Household],
            'total' => ['total', Per::Total],
            'upper case' => ['PERSON', Per::Person],
            'spaces' => [' household ', Per::Household],
            'unknown word' => ['people', Per::Total],
            'script' => ['"><script>', Per::Total],
            'empty' => ['', Per::Total],
            'missing' => [null, Per::Total],
            'array' => [['person'], Per::Total],
            'number' => [1, Per::Total],
        ];
    }

    #[DataProvider('perInputs')]
    public function testPerIsValidatedAndGarbageIsTheTotal(mixed $value, Per $expected): void
    {
        self::assertSame($expected, Per::fromInput($value));
        $request = new RequestModel();
        FrontController::readRequest($request, ['per' => $value], [], new CompareService());
        self::assertSame($expected, $request->per());
    }

    public function testGarbagePerGivesExactlyTheTotalPage(): void
    {
        $app = FrontControllerTest::app(FrontControllerTest::source());
        $range = ['start_date' => '2009-01-20', 'end_date' => '2017-01-19'];
        self::assertSame($app->handle($range, [])->body, $app->handle($range + ['per' => 'garbage'], [])->body);
        self::assertSame($app->handle($range, [])->body, $app->handle($range + ['per' => 'total'], [])->body);
    }

    // ---------- Links keep per ----------

    /** @return array<string, string> og: tags */
    private static function meta(string $html): array
    {
        preg_match_all('#<meta (?:property|name)="((?:og|twitter):[a-z:_]+)" content="([^"]*)">#', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $name, $content]) {
            $out[$name] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $out;
    }

    /** @return list<string> Every same-site href and form action on the page (decoded). */
    private static function pageLinks(string $html): array
    {
        preg_match_all('#(?:href|action)="(\.?/?\?[^"]*|\./[^"]*|/card\.php[^"]*)"#', $html, $m);
        return array_map(static fn (string $v): string => html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
    }

    public function testEveryLinkOnAPerPersonPageKeepsPer(): void
    {
        $app = FrontControllerTest::app(FrontControllerTest::source());
        $html = $app->handle(['start_date' => '2009-01-20', 'end_date' => '2017-01-19', 'per' => 'person', 'trend' => '0'], [])->body;
        $meta = self::meta($html);

        self::assertSame('https://usdebt.bengusa.pro/?start_date=2009-01-20&end_date=2017-01-19&per=person&trend=0', $meta['og:url']);
        self::assertStringContainsString('per=person', $meta['og:image']);
        self::assertStringStartsWith('U.S. debt per person, ', $meta['og:title']);
        self::assertStringContainsString('id="share_url" value="https://usdebt.bengusa.pro/?start_date=2009-01-20&amp;end_date=2017-01-19&amp;per=person&amp;trend=0"', $html);
        self::assertStringContainsString('<input type="hidden" name="per" value="person">', $html, 'the date form keeps it');

        $links = self::pageLinks($html);
        self::assertNotEmpty($links);
        foreach ($links as $link) {
            if ($link === './#results') {
                continue; // the date form (GET): per rides in its hidden field, checked above
            }
            if (str_contains($link, 'per=household') || $link === './?start_date=2009-01-20&end_date=2017-01-19&trend=0#results') {
                continue; // the switch's other choices: household, and the total (no per)
            }
            self::assertStringContainsString('per=person', $link, $link);
        }
        // The switch: the same view three ways, the current one marked.
        self::assertStringContainsString('href="./?start_date=2009-01-20&amp;end_date=2017-01-19&amp;trend=0#results"', $html);
        self::assertStringContainsString('href="./?start_date=2009-01-20&amp;end_date=2017-01-19&amp;per=household&amp;trend=0#results"', $html);
        self::assertMatchesRegularExpression('#href="\./\?start_date=2009-01-20&amp;end_date=2017-01-19&amp;per=person&amp;trend=0\#results" data-trend-link aria-current="true">Per person<#', $html);
    }

    public function testTotalPageLinksHaveNoPerExceptTheSwitch(): void
    {
        $html = FrontControllerTest::app(FrontControllerTest::source())->handle(['start_date' => '2009-01-20', 'end_date' => '2017-01-19'], [])->body;
        $withPer = array_values(array_filter(self::pageLinks($html), static fn (string $l): bool => str_contains($l, 'per=')));
        self::assertSame([
            './?start_date=2009-01-20&end_date=2017-01-19&per=person#results',
            './?start_date=2009-01-20&end_date=2017-01-19&per=household#results',
        ], $withPer);
        self::assertStringNotContainsString('per=', self::meta($html)['og:url']);
        self::assertStringNotContainsString('name="per"', $html);
    }

    public function testComparePerPersonByLinkAndByFormPostKeepsPer(): void
    {
        $app = FrontControllerTest::app(FrontControllerTest::source());
        $clinton = '727506000';
        $biden = '1611100800';

        $linked = $app->handle(['compare' => "{$clinton},{$biden}", 'per' => 'household'], []);
        self::assertSame(200, $linked->status);
        $meta = self::meta($linked->body);
        self::assertSame("https://usdebt.bengusa.pro/?compare={$clinton},{$biden}&per=household", $meta['og:url']);
        self::assertSame("https://usdebt.bengusa.pro/card.php?compare={$clinton},{$biden}&per=household", $meta['og:image']);
        self::assertSame('U.S. debt per household across presidential terms', $meta['og:title']);
        self::assertStringContainsString('The y axis is the change per household, in U.S. dollars.', $linked->body);
        $chart = FrontControllerTest::chartJson($linked->body);
        self::assertSame(['household', Per::UNIT_USD], [$chart['per'], $chart['unit']]);
        self::assertSame('households', $chart['divisor']['kind']);

        // The form posts to an address that carries per; the share link keeps it.
        self::assertStringContainsString('action="./?per=household#results"', $linked->body);
        $posted = $app->handle(['per' => 'person'], ['submit' => 'Compare', 'compare_pres' => [$clinton, $biden]]);
        self::assertSame(200, $posted->status);
        self::assertSame("https://usdebt.bengusa.pro/?compare={$clinton},{$biden}&per=person", self::meta($posted->body)['og:url']);
        self::assertSame('person', FrontControllerTest::chartJson($posted->body)['per']);

        // An error page keeps per in its links too.
        $error = $app->handle(['compare' => $clinton, 'per' => 'person'], []);
        self::assertSame(400, $error->status);
        self::assertStringContainsString('href="./?per=person"', $error->body, 'the home link');
    }

    public function testPerPersonHeadlineNumbersAndChartData(): void
    {
        $source = FrontControllerTest::source();
        $dataset = $source->load();
        $latest = $dataset->latest();
        $pop = new PopulationService();
        $html = FrontControllerTest::app($source)->handle(['start_date' => '2000-01-04', 'end_date' => '2026-11-09', 'per' => 'person'], [])->body;

        $perPerson = $latest->totalDebt / $pop->population($latest->effectiveDate);
        self::assertStringContainsString('<p class="stat-label">Debt today, per person</p>', $html);
        self::assertStringContainsString('<p class="stat-value num">' . \USDebt\Service\Html::compactMoney($perPerson) . '</p>', $html);
        self::assertStringContainsString('341.8 million people on Nov 9, 2026 (latest Census figure, 2025)', $html);
        self::assertStringContainsString('Change per person in this range', $html);
        self::assertStringContainsString('Per person, per day', $html);
        self::assertStringContainsString('Days after Jul 1, 2025 use the 2025 figure.', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));

        $chart = FrontControllerTest::chartJson($html);
        self::assertSame(['daily', 'person', Per::UNIT_USD], [$chart['type'], $chart['per'], $chart['unit']]);
        self::assertEquals(['kind' => 'population', 'anchor' => '07-01', 'latest_year' => 2025, 'latest_date' => '2025-07-01', 'latest_value' => 341784857], $chart['divisor']);
        $points = $chart['series'][0]['points'];
        self::assertEqualsWithDelta(round($perPerson, 2), $points[count($points) - 1][1], 1e-9);

        $total = FrontControllerTest::chartJson(FrontControllerTest::app($source)->handle(['start_date' => '2000-01-04', 'end_date' => '2026-11-09'], [])->body);
        self::assertSame(['total', Per::UNIT_TRILLION_USD], [$total['per'], $total['unit']]);
        self::assertArrayNotHasKey('divisor', $total);
    }

    // ---------- Site name in one place ----------

    public function testSiteNameLivesInOnePlace(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!in_array($file->getExtension(), ['php', 'js', 'css'], true)) {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            foreach (['Our U.S. Debt', 'U.S. Debt Tracker', SiteConfig::NAME] as $name) {
                if (str_contains($text, $name)) {
                    $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        self::assertSame(['lib/SiteConfig.php'], array_values(array_unique($found)));

        $html = FrontControllerTest::app(FrontControllerTest::source())->handle([], [])->body;
        self::assertStringContainsString('<title>' . SiteConfig::NAME . '</title>', $html);
        self::assertMatchesRegularExpression('#<a class="brand"[^>]*>' . preg_quote(SiteConfig::NAME, '#') . '</a>#', $html);
        self::assertSame(SiteConfig::NAME, self::meta($html)['og:site_name']);
    }
}
