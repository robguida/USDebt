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

    /** Run $work with PHP's error log pointed at a scratch file; returns the logged lines. */
    private static function loggedWhile(callable $work): array
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usdebt-log-' . bin2hex(random_bytes(6)) . '.log';
        $before = ini_set('error_log', $file);
        try {
            $work();
        } finally {
            ini_set('error_log', (string) $before);
        }
        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        @unlink($file);
        return $lines;
    }

    public function testABadYearIsSkippedAndLoggedOnceAndTheLineSpansTheGap(): void
    {
        $lines = self::loggedWhile(function (): void {
            $pop = PopulationService::fromYears(
                [2003 => 100_000_000, 'abc' => 5, 2004 => 136_600_000, 2005 => 0],
                [2003 => 50_000_000, 2004 => 50_366_000],
            );
            self::assertTrue($pop->available());
            self::assertTrue($pop->available(), 'asking again does not log again');
            self::assertSame(100_000_000.0, $pop->population('2003-07-01'));
            self::assertEqualsWithDelta(118_300_000.0, $pop->population('2003-12-31'), 100_000.0, 'the good years still interpolate');
            self::assertSame(136_600_000.0, $pop->population('2010-01-01'), 'the last good year is held');
        });
        self::assertCount(1, $lines, 'one log line for the bad entries, not one per entry or per call');
        self::assertStringContainsString('skipped 2 bad population entries (abc, 2005)', $lines[0]);
    }

    public function testFewerThanTwoGoodYearsMakesPerModesUnavailableWithAWarning(): void
    {
        $lines = self::loggedWhile(function (): void {
            $pop = PopulationService::fromYears([2003 => 0], [2003 => 1, 2004 => 2]);
            self::assertFalse($pop->available());
            self::assertFalse($pop->available());
        });
        self::assertCount(2, $lines, 'one line for the skipped entry, one warning about falling back');
        self::assertStringContainsString('skipped 1 bad population entry (2003)', $lines[0]);
        self::assertStringContainsString('fewer than 2 good years of population', $lines[1]);
        self::assertStringContainsString('fall back to the total', $lines[1]);
    }

    public function testThePageFallsBackToTheTotalWhenThereIsNoUsableCensusData(): void
    {
        $html = '';
        self::loggedWhile(function () use (&$html): void {
            $pop = PopulationService::fromYears([2003 => 0], [2003 => 1]);
            $app = new FrontController(new \USDebt\Controller\USDebtController(FrontControllerTest::source(), new CompareService(null, $pop)));
            $html = $app->handle(['start_date' => '2000-01-04', 'end_date' => '2026-11-09', 'per' => 'person'], [])->body;
        });
        $chart = FrontControllerTest::chartJson($html);
        self::assertSame(['total', 'trillion_usd'], [$chart['per'], $chart['unit']], 'the total is shown, not an error page');
        self::assertStringContainsString('Debt today', $html);
        // the footer's copyright line names the Census data in every mode, so it is left out of this check
        self::assertStringNotContainsString('Census', preg_replace('#<p class="copyright">.*?</p>#s', '', $html));
    }

    public function testMissingCensusFileAlsoFallsBackToTheTotal(): void
    {
        $pop = new PopulationService(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'usdebt-no-such-file.json');
        self::loggedWhile(function () use ($pop): void {
            self::assertFalse($pop->available());
        });
    }

    // ---------- Fix round: one rounding, per words, early hold, series names ----------

    /** The chart summary and the Stats tab show the same growth rate, rounded once from the raw value. */
    public function testGrowthRateIsTheSameStringUnderTheChartAndInStats(): void
    {
        foreach (self::modes() as [$mode]) {
            foreach ([['2000-01-04', '2026-11-09'], ['2008-03-03', '2019-09-30'], ['1993-01-04', '2026-11-09']] as [$start, $end]) {
                $query = ['start_date' => $start, 'end_date' => $end] + ($mode === 'total' ? [] : ['per' => $mode]);
                $text = html_entity_decode(FrontControllerTest::app(FrontControllerTest::source())->handle($query, [])->body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                self::assertSame(1, preg_match('#Growth curve: about ([^ ]+) per year#', $text, $chart), "$mode $start chart");
                self::assertSame(1, preg_match('#<dt>Growth curve</dt>\s*<dd class="num">About ([^ ]+) per year</dd>#', $text, $stats), "$mode $start stats");
                self::assertSame($chart[1], $stats[1], "$mode $start");
            }
        }

        // The same raw number reaches both places, so the one rounding cannot differ.
        $entries = [];
        foreach (['2024-01-01', '2024-06-03', '2025-01-01', '2025-09-15'] as $date) {
            $t = (new DateTimeImmutable('2024-01-01'))->diff(new DateTimeImmutable($date))->days;
            $entries[] = new DebtEntry($date, 30_000_000_000_000.0 * 1.083496 ** ($t / 365));
        }
        $curve = array_values(array_filter(ChartData::daily($entries)['series'], static fn (array $s): bool => $s['key'] === 'curve'))[0];
        self::assertSame(StatsCalculator::summarize($entries)['growth_pct_per_year'], $curve['growth_pct_per_year']);
        self::assertSame('+8.3%', \USDebt\Service\Html::signedPercent($curve['growth_pct_per_year']), 'a raw 8.3496 rounds once, down (rounding to 8.35 first would give 8.4)');
    }

    #[DataProvider('modes')]
    public function testTrendAndCompareLinesKeepThePerWords(string $mode): void
    {
        $per = Per::from($mode);
        $words = $per->isTotal() ? '' : ' per ' . $mode;
        $app = FrontControllerTest::app(FrontControllerTest::source());
        $range = $app->handle(['start_date' => '2000-01-04', 'end_date' => '2026-11-09'] + ($per->isTotal() ? [] : ['per' => $mode]), [])->body;
        $text = html_entity_decode($range, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        self::assertSame(1, preg_match('#Trend: about [^(]*?' . preg_quote($words, '#') . ' per day \(straight line\)#', $text), 'chart summary');
        self::assertSame(1, preg_match('#<dt>Trend \(straight line\)</dt>\s*<dd class="num">About [^<]*?' . preg_quote($words, '#') . ' per day</dd>#', $text), 'Stats row');

        $picks = implode(',', array_keys(\USDebt\Service\PresidentService::getPresidentConfig()));
        $compare = html_entity_decode($app->handle(['compare' => $picks] + ($per->isTotal() ? [] : ['per' => $mode]), [])->body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = preg_match_all('#<li>[^<]*: [+-]\$[0-9.,]+(?: [a-z]+)?' . preg_quote($words, '#') . ' after [0-9]+ months? in office\.'
            . ' Trend: about [+-]\$[0-9.,]+(?: [a-z]+)?' . preg_quote($words, '#') . ' per month \(straight line\)\.#', $compare);
        $all = preg_match_all('#<li>[^<]* after [0-9]+ months? in office\.#', $compare);
        self::assertGreaterThan(0, $all);
        self::assertSame($all, $lines, 'every Compare line says what it is per');
    }

    #[DataProvider('modes')]
    public function testTheEmailLinkCarriesTheCensusSourceInPerModes(string $mode): void
    {
        $per = Per::from($mode);
        $html = FrontControllerTest::app(FrontControllerTest::source())->handle(['start_date' => '2000-01-04', 'end_date' => '2026-11-09'] + ($per->isTotal() ? [] : ['per' => $mode]), [])->body;
        self::assertSame(1, preg_match('#data-share="email" href="(mailto:[^"]*)"#', $html, $m));
        $body = rawurldecode(explode('&body=', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))[1]);
        self::assertStringEndsWith(\USDebt\Service\ShareText::emailNote($per), $body);
        self::assertSame(!$per->isTotal(), str_contains((string) preg_replace('#<p class="copyright">.*?</p>#s', '', $body), 'Census'), 'the Census line is there in per modes only');

        // The link builder agrees with the page.
        $links = \USDebt\Service\ShareLinks::networks('https://x.test/', 'Text.', 'Subject', $per);
        self::assertSame('mailto:?subject=Subject&body=' . rawurlencode(\USDebt\Service\ShareText::emailBody('Text.', 'https://x.test/', $per)), $links['email']);
    }

    #[DataProvider('modes')]
    public function testEarlyHoldIsSaidWhenTheViewStartsBeforeTheFirstCensusFigure(string $mode): void
    {
        $per = Per::from($mode);
        $app = FrontControllerTest::app(FrontControllerTest::source());
        $say = 'The 1993 Census figure is used for dates before it.';
        $extra = $per->isTotal() ? [] : ['per' => $mode];
        $early = html_entity_decode($app->handle(['start_date' => '1993-01-04', 'end_date' => '1993-12-31'] + $extra, [])->body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $late = html_entity_decode($app->handle(['start_date' => '2000-01-04', 'end_date' => '2001-12-31'] + $extra, [])->body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $picks = implode(',', array_keys(\USDebt\Service\PresidentService::getPresidentConfig()));
        $compare = html_entity_decode($app->handle(['compare' => $picks] + $extra, [])->body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $laterTerms = html_entity_decode($app->handle(['compare' => '3,4'] + $extra, [])->body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($per->isTotal()) {
            foreach ([$early, $compare] as $html) {
                self::assertStringNotContainsString('Census figure is used for dates before it', $html);
            }
            return;
        }
        // Under the chart (footnote) and in the Stats method note.
        self::assertSame(2, substr_count($early, $say), 'footnote and Stats note');
        self::assertStringNotContainsString('dates before it', $late);
        // Compare (Clinton starts in January 1993): the footnote says it.
        self::assertSame(1, substr_count($compare, $say));
        self::assertStringNotContainsString('dates before it', $laterTerms);
        // The words come from one place.
        self::assertStringContainsString($say, \USDebt\Service\ShareText::censusFootnote($per, 2025, 1993));
        self::assertStringContainsString($say, \USDebt\Service\ShareText::method($per, null, 1993));
        self::assertStringNotContainsString('dates before it', \USDebt\Service\ShareText::censusFootnote($per, 2025));
    }

    #[DataProvider('modes')]
    public function testTheDebtSeriesNamesComeFromTheServerAndTheScriptDoesNotStripLabels(string $mode): void
    {
        $per = Per::from($mode);
        $debt = ChartData::daily([new DebtEntry('2026-01-02', 38.0e12), new DebtEntry('2026-01-05', 38.1e12)], $per)['series'][0];
        $names = ['total' => ['Total debt (trillions USD)', 'Total debt'], 'person' => ['Debt per person (USD)', 'Debt per person'], 'household' => ['Debt per household (USD)', 'Debt per household']];
        self::assertSame($names[$mode], [$debt['label'], $debt['name']]);
        self::assertSame($names[$mode][0], \USDebt\Service\ShareText::debtSeries($per)['label']);

        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/js/usdebt.js');
        self::assertStringNotContainsString('trillions USD', $js, 'no unit text in the script');
        self::assertStringNotContainsString('(?:trillions', $js);
        self::assertStringContainsString('item.dataset.name || item.dataset.label', $js);
        $chartData = (string) file_get_contents(dirname(__DIR__, 2) . '/src/lib/Service/ChartData.php');
        self::assertStringNotContainsString('(USD)', $chartData, 'the labels live in ShareText');
        self::assertStringNotContainsString('trillions USD', $chartData);
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

        self::assertSame('https://bengusa.pro/ourusdebt/?start_date=2009-01-20&end_date=2017-01-19&per=person&trend=0', $meta['og:url']);
        self::assertStringContainsString('per=person', $meta['og:image']);
        self::assertStringStartsWith('U.S. debt per person, ', $meta['og:title']);
        self::assertStringContainsString('id="share_url" value="https://bengusa.pro/ourusdebt/?start_date=2009-01-20&amp;end_date=2017-01-19&amp;per=person&amp;trend=0"', $html);
        self::assertStringContainsString('<input type="hidden" name="per" value="person">', $html, 'the date form keeps it');

        $links = self::pageLinks($html);
        self::assertNotEmpty($links);
        foreach ($links as $link) {
            if ($link === './#results') {
                continue; // the date form (GET): per rides in its hidden field, checked above
            }
            if (str_contains($link, 'per=household') || $link === './?start_date=2009-01-20&end_date=2017-01-19&trend=0') {
                continue; // the switch's other choices: household, and the total (no per)
            }
            self::assertStringContainsString('per=person', $link, $link);
        }
        // The switch: the same view three ways (dates and trend kept), the current one marked.
        self::assertSame([
            ['./?start_date=2009-01-20&end_date=2017-01-19&trend=0', 'Total', false],
            ['./?start_date=2009-01-20&end_date=2017-01-19&per=person&trend=0', 'Per person', true],
            ['./?start_date=2009-01-20&end_date=2017-01-19&per=household&trend=0', 'Per household', false],
        ], self::switchOptions($html));
    }

    /**
     * The "Show numbers as" switch: [href, label, picked] for each option, in order.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function switchOptions(string $html): array
    {
        preg_match('#<nav class="per-switch" aria-labelledby="per_label">(.*?)</nav>#s', $html, $nav);
        self::assertNotEmpty($nav, 'the switch is on the page');
        preg_match_all('#<a class="per-option" href="([^"]*)" data-trend-link data-per="(total|person|household)"( aria-current="true")?>([^<]*)</a>#', $nav[1], $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $a) {
            $out[] = [html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $a[4], $a[3] !== ''];
        }
        return $out;
    }

    public function testTotalPageLinksHaveNoPerExceptTheSwitch(): void
    {
        $html = FrontControllerTest::app(FrontControllerTest::source())->handle(['start_date' => '2009-01-20', 'end_date' => '2017-01-19'], [])->body;
        $withPer = array_values(array_filter(self::pageLinks($html), static fn (string $l): bool => str_contains($l, 'per=')));
        self::assertSame([
            './?start_date=2009-01-20&end_date=2017-01-19&per=person',
            './?start_date=2009-01-20&end_date=2017-01-19&per=household',
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
        self::assertSame("https://bengusa.pro/ourusdebt/?compare={$clinton},{$biden}&per=household", $meta['og:url']);
        self::assertSame("https://bengusa.pro/ourusdebt/card.php?compare={$clinton},{$biden}&per=household", $meta['og:image']);
        self::assertSame('U.S. debt per household across presidential terms', $meta['og:title']);
        self::assertStringContainsString('The y axis is the change per household, in U.S. dollars.', $linked->body);
        $chart = FrontControllerTest::chartJson($linked->body);
        self::assertSame(['household', Per::UNIT_USD], [$chart['per'], $chart['unit']]);
        self::assertSame('households', $chart['divisor']['kind']);

        // The form posts to an address that carries per; the share link keeps it.
        self::assertStringContainsString('action="./?per=household#results"', $linked->body);
        $posted = $app->handle(['per' => 'person'], ['submit' => 'Compare', 'compare_pres' => [$clinton, $biden]]);
        self::assertSame(200, $posted->status);
        self::assertSame("https://bengusa.pro/ourusdebt/?compare={$clinton},{$biden}&per=person", self::meta($posted->body)['og:url']);
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
        self::assertStringContainsString('<p class="stat-label">Debt per person today</p>', $html);
        self::assertStringContainsString('<p class="stat-value num">' . \USDebt\Service\Html::compactMoney($perPerson) . '</p>', $html);
        self::assertStringContainsString('341.8 million people on Nov 9, 2026 (latest Census figure, 2025)', $html);
        self::assertStringContainsString('Change per person in this range', $html);
        self::assertStringContainsString('<p class="stat-label">Per person per day</p>', $html);
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

    // ---------- Your Share on the page: switch, labels, units, footnote ----------

    /** @return array<string, array{0: string}> */
    public static function modes(): array
    {
        return ['total' => ['total'], 'per person' => ['person'], 'per household' => ['household']];
    }

    #[DataProvider('modes')]
    public function testRangePageLabelsUnitsAndFootnoteFollowTheMode(string $mode): void
    {
        $per = Per::from($mode);
        $query = ['start_date' => '2000-01-04', 'end_date' => '2026-11-09'] + ($per->isTotal() ? [] : ['per' => $mode]);
        $html = FrontControllerTest::app(FrontControllerTest::source())->handle($query, [])->body;
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = [
            'total' => ['Debt today', 'Change in this range', 'Per day', 'Starting debt amount', 'Last debt amount of date range',
                'Change during the date range', 'Average change per day', 'The debt over time', 'Debt (dollars)', 'Total debt'],
            'person' => ['Debt per person today', 'Change per person in this range', 'Per person per day', 'Per person at start',
                'Per person at end', 'Change per person', 'Average per person per day', 'Debt per person over time', 'Debt per person (dollars)', 'Debt per person'],
            'household' => ['Debt per household today', 'Change per household in this range', 'Per household per day', 'Per household at start',
                'Per household at end', 'Change per household', 'Average per household per day', 'Debt per household over time', 'Debt per household (dollars)', 'Debt per household'],
        ][$mode];
        [$today, $change, $perDay, $start, $end, $statsChange, $statsPerDay, $heading, $axis, $column] = $words;

        // Headline cards.
        preg_match_all('#<p class="stat-label">([^<]*)</p>#', $text, $labels);
        self::assertSame([$today, $change, $perDay], $labels[1]);
        // Stats rows.
        foreach ([$start, $end, $statsChange, $statsPerDay] as $row) {
            self::assertStringContainsString('<dt>' . $row . '</dt>', $text);
        }
        // Results heading, table column, y axis title (drawn by the page script from the chart data).
        self::assertStringContainsString('<h2 id="results-title" class="card-title">' . $heading . '</h2>', $text);
        self::assertStringContainsString('<th scope="col" class="currency">' . $column . '</th>', $text);
        $chart = FrontControllerTest::chartJson($html);
        self::assertSame($axis, $chart['y_title']);
        self::assertSame($per->unit(), $chart['unit']);

        // Amounts: per person and per household are dollars, never trillions.
        preg_match_all('#<p class="stat-value num">([^<]*)</p>#', $text, $values);
        self::assertCount(3, $values[1]);
        if (!$per->isTotal()) {
            foreach ($values[1] as $value) {
                self::assertMatchesRegularExpression('/^[+-]?\$[0-9]{1,3}(,[0-9]{3})*\.[0-9]{2}$/', $value);
            }
            preg_match('#<section class="card results".*</section>#s', $text, $results);
            self::assertStringNotContainsString('trillion', $results[0], 'the chart, stats and table are in dollars');
        }

        // The Census footnote under the chart: per modes only, with the latest year.
        $footnote = 'Per-person and per-household figures divide the debt by U.S. Census Bureau population and household counts.'
            . ' Counts are yearly; days between years are estimated in straight-line steps, and the latest Census figure (2025) is used for recent dates.'
            . ' Debt: U.S. Treasury, Debt to the Penny.';
        if ($per->isTotal()) {
            self::assertStringNotContainsString('chart-source', $html);
            self::assertStringNotContainsString('Census', (string) preg_replace('#<p class="copyright">.*?</p>#s', '', $text)); // the footer copyright names the Census data
        } else {
            self::assertStringContainsString('<p class="footnote chart-source">' . $footnote . '</p>', $text);
        }
    }

    #[DataProvider('modes')]
    public function testSwitchShowsThePickedModeAndKeepsDatesAndTrend(string $mode): void
    {
        $per = Per::from($mode);
        $app = FrontControllerTest::app(FrontControllerTest::source());
        $html = $app->handle(['start_date' => '2000-01-04', 'end_date' => '2026-11-09', 'per' => $mode, 'trend' => '0'], [])->body;

        // A labelled switch at the top of the page, above the headline numbers.
        self::assertStringContainsString('<section class="per-bar" id="per_switch" aria-label="Choose how to show the numbers">', $html);
        self::assertStringContainsString('<span class="per-label" id="per_label">Show numbers as</span>', $html);
        self::assertLessThan(strpos($html, 'class="headline"'), strpos($html, 'id="per_switch"'));
        self::assertSame(1, substr_count($html, 'class="per-switch"'), 'one switch only');
        $help = [
            'total' => 'The whole national debt.',
            'person' => 'The debt divided by the U.S. population: every resident, all ages.',
            'household' => 'The debt divided by the number of U.S. households.',
        ];
        self::assertStringContainsString('<p class="per-help">' . $help[$mode] . '</p>', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $range = './?start_date=2000-01-04&end_date=2026-11-09';
        self::assertSame([
            [$range . '&trend=0', 'Total', $per === Per::Total],
            [$range . '&per=person&trend=0', 'Per person', $per === Per::Person],
            [$range . '&per=household&trend=0', 'Per household', $per === Per::Household],
        ], self::switchOptions($html));

        // Trend on: no trend=0; the home page keeps nothing but per.
        $home = $app->handle(['per' => $mode], [])->body;
        self::assertSame(['./', './?per=person', './?per=household'], array_column(self::switchOptions($home), 0));
    }

    public function testSwitchOnCompareKeepsThePicksByLinkAndAfterTheFormPost(): void
    {
        $all = array_keys(\USDebt\Service\PresidentService::getPresidentConfig());
        $picks = implode(',', $all);
        $app = FrontControllerTest::app(FrontControllerTest::source());
        foreach ([
            'link' => $app->handle(['compare' => $picks, 'per' => 'person'], [])->body,
            'post' => $app->handle(['per' => 'person'], ['submit' => 'Compare', 'compare_pres' => array_map('strval', $all)])->body,
        ] as $how => $html) {
            self::assertSame([
                ['./?compare=' . $picks, 'Total', false],
                ['./?compare=' . $picks . '&per=person', 'Per person', true],
                ['./?compare=' . $picks . '&per=household', 'Per household', false],
            ], self::switchOptions($html), $how);

            $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $chart = FrontControllerTest::chartJson($html);
            self::assertSame(['usd', 'Change per person since taking office'], [$chart['unit'], $chart['y_title']], $how);
            self::assertStringContainsString('<p class="footnote chart-source">Per-person and per-household figures divide the debt', $text, $how);
            self::assertStringContainsString('aria-label="Line chart comparing the change in debt per person during each picked presidential term"', $text, $how);
            // Each term's change per person, in dollars.
            preg_match_all('#<li>([^<]*) after [0-9]+ months? in office\.#', $text, $lines);
            self::assertCount(count(array_unique(array_column(\USDebt\Service\PresidentService::getPresidentConfig(), 'pres'))), $lines[1], $how);
            foreach ($lines[1] as $line) {
                self::assertMatchesRegularExpression('/: [+-]?\$[0-9]{1,3}(,[0-9]{3})*\.[0-9]{2} per person$/', $line, $how);
            }
        }

        // The total Compare keeps its own axis title and has no footnote.
        $total = $app->handle(['compare' => $picks], [])->body;
        self::assertSame(['trillion_usd', 'Change since taking office'], [FrontControllerTest::chartJson($total)['unit'], FrontControllerTest::chartJson($total)['y_title']]);
        self::assertStringNotContainsString('chart-source', $total);
    }

    public function testScriptFormatsDollarsOrTrillionsByUnitAndTheSwitchKeepsPer(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Public/js/usdebt.js');
        self::assertStringNotContainsString('trillions(', $js, 'every amount goes through formatAmount()');
        self::assertStringContainsString('formatAmount(item.parsed.y, unit, false)', $js, 'date-range tooltip');
        self::assertStringContainsString('formatAmount(value, unit, true)', $js, 'date-range axis');
        self::assertStringContainsString('formatAmount(item.parsed.y, unit, false, true)', $js, 'Compare tooltip');
        self::assertStringContainsString('formatAmount(value, unit, true, true)', $js, 'Compare axis');

        $node = self::findNode();
        if ($node === null) {
            self::markTestSkipped('Node is not installed; the static checks above still ran.');
        }
        $check = dirname(__DIR__) . '/js/per-format.check.js';
        exec(escapeshellarg($node) . ' ' . escapeshellarg($check) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    private static function findNode(): ?string
    {
        $finder = DIRECTORY_SEPARATOR === '\\' ? 'where node 2>NUL' : 'command -v node 2>/dev/null';
        $found = trim((string) shell_exec($finder));
        $first = $found === '' ? '' : trim(strtok($found, "\r\n"));
        return $first !== '' && is_file($first) ? $first : null;
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
