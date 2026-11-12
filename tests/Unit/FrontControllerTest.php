<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use Error;
use PHPUnit\Framework\TestCase;
use USDebt\Controller\FrontController;
use USDebt\Controller\USDebtController;
use USDebt\Exception\DataUnavailableException;
use USDebt\Exception\HttpException;
use USDebt\Model\DebtDataset;
use USDebt\Model\DebtEntry;
use USDebt\Service\CompareService;
use USDebt\Service\DebtRepository;
use USDebt\Service\DebtSource;
use USDebt\Service\FiscalDataClient;
use USDebt\Service\Html;
use USDebt\Service\PresidentService;
use USDebt\Tests\Support\FakeHttpFetcher;
use USDebt\Tests\Support\TempDir;

final class FrontControllerTest extends TestCase
{
    use TempDir;

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public static function app(DebtSource $source): FrontController
    {
        return new FrontController(new USDebtController($source, new CompareService()));
    }

    /** Weekly rows from 1993 to 2026 so every term has data. */
    public static function source(bool $stale = false): DebtSource
    {
        $entries = [];
        $day = new \DateTimeImmutable('1993-01-04');
        $amount = 4.0e12;
        while ($day <= new \DateTimeImmutable('2026-11-09')) {
            $entries[] = new DebtEntry($day->format('Y-m-d'), $amount);
            $amount += 1.0e10;
            $day = $day->modify('+7 days');
        }
        $dataset = new DebtDataset($entries, '2026-11-12', $stale);
        return new class ($dataset) implements DebtSource {
            public function __construct(private readonly DebtDataset $dataset)
            {
            }

            public function load(): DebtDataset
            {
                return $this->dataset;
            }
        };
    }

    /** A source that serves exactly these entries. */
    public static function sourceOf(DebtEntry ...$entries): DebtSource
    {
        $dataset = new DebtDataset(array_values($entries), '2026-11-12');
        return new class ($dataset) implements DebtSource {
            public function __construct(private readonly DebtDataset $dataset)
            {
            }

            public function load(): DebtDataset
            {
                return $this->dataset;
            }
        };
    }

    /** @return array<string, mixed> The chart JSON data block on the page. */
    public static function chartJson(string $html): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" id="chart-data">(.*?)</script>#s', $html, $m), 'chart data block');
        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testUnexpectedErrorShowsOnlyTheGenericMessage(): void
    {
        $broken = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new Error('secret detail /var/www/usdebt/src/lib/x.php');
            }
        };
        $html = self::app($broken)->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-01'], [])->body;

        self::assertStringContainsString(Html::e(FrontController::GENERIC_ERROR), $html);
        self::assertStringNotContainsString('secret detail', $html);
        self::assertStringNotContainsString('/var/www', $html);
        self::assertStringContainsString('id="search"', $html, 'the search form still renders');
    }

    public function testTreasuryDownWithNoSavedCopyShowsFriendlyMessage(): void
    {
        $repo = new DebtRepository(
            new FiscalDataClient(new FakeHttpFetcher(new HttpException('timeout after 10s'))),
            $this->makeTempDir()
        );
        $html = self::app($repo)->handle([], [])->body;

        self::assertStringContainsString(Html::e(DataUnavailableException::MESSAGE), $html);
        self::assertStringNotContainsString('timeout after 10s', $html);
    }

    public function testBadDateInputIsRejectedAndNotEchoed(): void
    {
        $html = self::app(self::source())->handle(['start_date' => '"><script>alert(1)</script>'], [])->body;

        self::assertStringContainsString('must be a real date', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function testDateRangeViewRendersChartJsonStatsAndTable(): void
    {
        $html = self::app(self::source())->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09'], [])->body;

        $chart = self::chartJson($html);
        self::assertSame('daily', $chart['type']);
        self::assertSame('debt', $chart['series'][0]['key']);
        self::assertSame('2026-10-05', $chart['series'][0]['points'][0][0], 'first weekly row in range, oldest first');
        self::assertIsFloat($chart['series'][0]['points'][0][1]);
        // Straight trend line over the shown weeks (+$10 billion a week) and its projection.
        // Then the growth curve and its projection, over the same days.
        self::assertSame(['debt', 'trend', 'projection', 'curve', 'curve_projection'], array_column($chart['series'], 'key'));
        self::assertSame(array_column($chart['series'][0]['points'], 0), array_column($chart['series'][1]['points'], 0));
        self::assertSame(array_column($chart['series'][0]['points'], 0), array_column($chart['series'][3]['points'], 0));
        self::assertSame(array_column($chart['series'][2]['points'], 0), array_column($chart['series'][4]['points'], 0));
        self::assertEqualsWithDelta(0.01 / 7, $chart['series'][1]['slope_per_day'], 1e-9);
        self::assertTrue($chart['series'][2]['projection']);
        self::assertCount(9, $chart['series'][2]['points'], '25% of a 35-day range, rounded up');
        self::assertSame('2026-11-10', $chart['series'][2]['points'][0][0]);
        self::assertStringContainsString('Trend (straight line)', $html);
        self::assertStringContainsString('About +$1.43 billion per day', $html);
        self::assertMatchesRegularExpression('#<dt>Growth curve</dt>\s*<dd class="num">About \+[0-9.]+% per year</dd>#', $html);
        self::assertStringContainsString('Starting debt amount', $html);
        self::assertStringContainsString('<td class="currency">$', $html);
        self::assertStringNotContainsString('TM0.', $html, 'timing footer removed');
        self::assertStringNotContainsString('Showing Treasury data saved on', $html);
    }

    public function testEmptyDateRangeShowsMessage(): void
    {
        $html = self::app(self::source())->handle(['start_date' => '1980-01-01', 'end_date' => '1980-02-01'], [])->body;

        self::assertStringContainsString('There is no Treasury data for this date range', $html);
    }

    public function testReversedDatesAreRejected(): void
    {
        $html = self::app(self::source())->handle(['start_date' => '2026-11-01', 'end_date' => '2026-10-01'], [])->body;

        self::assertStringContainsString('start date must be on or before the end date', $html);
    }

    public function testStaleDataShowsANotice(): void
    {
        $html = self::app(self::source(true))->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09'], [])->body;

        self::assertStringContainsString('Showing Treasury data saved on 2026-11-12', $html);
    }

    public function testCompareAllPresidentsAtOnce(): void
    {
        $all = array_map('strval', array_keys(PresidentService::getPresidentConfig()));
        $html = self::app(self::source())->handle([], ['submit' => 'Compare', 'compare_pres' => $all])->body;

        $chart = self::chartJson($html);
        self::assertSame('compare', $chart['type']);
        $series = $chart['series'];
        self::assertCount(5, $series, 'one line per president; both Trump terms are one line');
        foreach ($series as $s) {
            self::assertIsInt($s['points'][0][0]);
            self::assertIsFloat($s['points'][1][1]);
            self::assertIsArray($s['trend'], 'every president has a trend line');
            self::assertCount(count($s['points']), $s['trend']['points'], 'fitted over that series\' own months');
            self::assertSame(array_column($s['points'], 0), array_column($s['trend']['points'], 0));
            self::assertGreaterThan(0, $s['trend']['slope_per_month']);
        }
        self::assertArrayNotHasKey('projection', $series[0]['trend'], 'no projection on Compare');
        self::assertSame(count($all), substr_count($html, 'checked="checked"'), 'all boxes stay ticked');
    }

    public function testStatusCodes(): void
    {
        $ok = self::app(self::source());
        self::assertSame(200, $ok->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09'], [])->status);
        self::assertSame(200, $ok->handle([], ['submit' => 'Compare', 'compare_pres' => ['727506000', '979966800']])->status);
        self::assertSame(400, $ok->handle(['start_date' => '2026-02-30'], [])->status, 'bad date');
        self::assertSame(400, $ok->handle(['start_date' => '2026-11-01', 'end_date' => '2026-10-01'], [])->status, 'reversed dates');
        self::assertSame(400, $ok->handle([], ['submit' => 'Compare', 'compare_pres' => ['727506000']])->status, 'one pick');

        $down = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new DataUnavailableException();
            }
        };
        $response = self::app($down)->handle([], []);
        self::assertSame(503, $response->status, 'no data at all');
        self::assertStringContainsString(Html::e(DataUnavailableException::MESSAGE), $response->body);

        $broken = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new Error('boom');
            }
        };
        self::assertSame(500, self::app($broken)->handle([], [])->status);
    }

    public function testArrayParametersAreIgnoredNotFatal(): void
    {
        $response = self::app(self::source())->handle(
            ['start_date' => ['2026-10-01'], 'end_date' => ['x' => 'y'], 'submit' => ['compare']],
            []
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('id="chart-data"', $response->body, 'falls back to the default view');
        self::assertStringNotContainsString('Array', $response->body);

        $compare = self::app(self::source())->handle([], ['submit' => 'Compare', 'compare_pres' => 'not-a-list']);
        self::assertSame(400, $compare->status);
        self::assertStringContainsString('Please pick at least 2 presidents to compare.', $compare->body);
    }

    public function testNoSessionAndNoCookies(): void
    {
        self::app(self::source())->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09'], []);
        self::app(self::source())->handle([], ['submit' => 'Compare', 'compare_pres' => ['727506000', '979966800']]);

        self::assertSame(PHP_SESSION_NONE, session_status(), 'no session is started');
        self::assertSame([], preg_grep('/^Set-Cookie:/i', headers_list()));

        // The CLI does not record headers, so also prove no code can send a cookie.
        $src = dirname(__DIR__, 2) . '/src';
        $files = [$src . '/Public/index.php'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src . '/lib', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $file) {
            self::assertDoesNotMatchRegularExpression(
                '/\b(session_start|setcookie|setrawcookie)\s*\(|Set-Cookie/i',
                (string) file_get_contents($file),
                $file
            );
        }
    }

    public function testATinyValueOnAShortRangeStillShowsThePageWithoutACurve(): void
    {
        $app = self::app(self::sourceOf(
            new DebtEntry('2026-01-01', 1e-300),
            new DebtEntry('2026-01-02', 10.0e12),
        ));
        $response = $app->handle(['start_date' => '2026-01-01', 'end_date' => '2026-01-02'], []);

        self::assertSame(200, $response->status);
        self::assertSame(['debt', 'trend', 'projection'], array_column(self::chartJson($response->body)['series'], 'key'));
        self::assertStringNotContainsString('Growth curve', $response->body);
    }

    public function testNearZeroGrowthShowsZeroPercentWithoutASign(): void
    {
        foreach ([1.0, -1.0] as $nudge) {
            $app = self::app(self::sourceOf(
                new DebtEntry('2026-01-01', 10.0e12),
                new DebtEntry('2026-01-31', 10.0e12 + $nudge * 1e6),
            ));
            $html = $app->handle(['start_date' => '2026-01-01', 'end_date' => '2026-01-31'], [])->body;

            self::assertStringContainsString('Growth curve: about 0.0% per year.', $html);
            self::assertMatchesRegularExpression('#<dt>Growth curve</dt>\s*<dd class="num">About 0\.0% per year</dd>#', $html);
            self::assertStringNotContainsString('+0.0%', $html);
            self::assertStringNotContainsString('-0.0%', $html);
        }
    }

    public function testCompareNeedsTwoRealPresidents(): void
    {
        $html = self::app(self::source())->handle([], ['submit' => 'Compare', 'compare_pres' => ['727506000', 'bogus', '727506000']])->body;

        self::assertStringContainsString('Please pick at least 2 presidents to compare.', $html);
    }
}
