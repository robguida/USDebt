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

    private static function app(DebtSource $source): FrontController
    {
        return new FrontController(new USDebtController($source, new CompareService()));
    }

    /** Weekly rows from 1993 to 2026 so every term has data. */
    private static function source(bool $stale = false): DebtSource
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

    public function testUnexpectedErrorShowsOnlyTheGenericMessage(): void
    {
        $broken = new class implements DebtSource {
            public function load(): DebtDataset
            {
                throw new Error('secret detail /var/www/usdebt/src/lib/x.php');
            }
        };
        $html = self::app($broken)->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-01'], []);

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
        $html = self::app($repo)->handle([], []);

        self::assertStringContainsString(Html::e(DataUnavailableException::MESSAGE), $html);
        self::assertStringNotContainsString('timeout after 10s', $html);
    }

    public function testBadDateInputIsRejectedAndNotEchoed(): void
    {
        $html = self::app(self::source())->handle(['start_date' => '"><script>alert(1)</script>'], []);

        self::assertStringContainsString('must be a real date', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function testDateRangeViewRendersChartJsonStatsAndTable(): void
    {
        $html = self::app(self::source())->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09'], []);

        self::assertMatchesRegularExpression('/var chartData = \{"labels":\[[^\]]+\],"values":\[[0-9.,]+\]\};/', $html);
        self::assertStringContainsString('Starting debt amount', $html);
        self::assertStringContainsString('<td class="currency">$', $html);
        self::assertStringNotContainsString('TM0.', $html, 'timing footer removed');
        self::assertStringNotContainsString('Showing Treasury data saved on', $html);
    }

    public function testEmptyDateRangeShowsMessage(): void
    {
        $html = self::app(self::source())->handle(['start_date' => '1980-01-01', 'end_date' => '1980-02-01'], []);

        self::assertStringContainsString('There is no Treasury data for this date range', $html);
    }

    public function testReversedDatesAreRejected(): void
    {
        $html = self::app(self::source())->handle(['start_date' => '2026-11-01', 'end_date' => '2026-10-01'], []);

        self::assertStringContainsString('start date must be on or before the end date', $html);
    }

    public function testStaleDataShowsANotice(): void
    {
        $html = self::app(self::source(true))->handle(['start_date' => '2026-10-01', 'end_date' => '2026-11-09'], []);

        self::assertStringContainsString('Showing Treasury data saved on 2026-11-12', $html);
    }

    public function testCompareAllPresidentsAtOnce(): void
    {
        $all = array_map('strval', array_keys(PresidentService::getPresidentConfig()));
        $html = self::app(self::source())->handle([], ['submit' => 'Compare', 'compare_pres' => $all]);

        self::assertMatchesRegularExpression('/var data = (\[.*\]);/', $html);
        preg_match('/var data = (\[.*\]);/', $html, $m);
        $series = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(5, $series, 'one line per president; both Trump terms are one line');
        foreach ($series as $s) {
            self::assertIsInt($s['data'][0][0]);
            self::assertIsFloat($s['data'][1][1]);
        }
        self::assertSame(count($all), substr_count($html, 'checked="checked"'), 'all boxes stay ticked');
    }

    public function testCompareNeedsTwoRealPresidents(): void
    {
        $html = self::app(self::source())->handle([], ['submit' => 'Compare', 'compare_pres' => ['727506000', 'bogus', '727506000']]);

        self::assertStringContainsString('Please pick at least 2 presidents to compare.', $html);
    }
}
