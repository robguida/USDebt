<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use USDebt\Model\DebtEntry;
use USDebt\Service\ChartData;
use USDebt\Service\TrendService;

final class TrendServiceTest extends TestCase
{
    private const T = 1_000_000_000_000;

    /**
     * One entry per calendar day from $start, value = $intercept + $slope * day (trillions).
     *
     * @return list<DebtEntry>
     */
    private static function line(string $start, int $days, float $intercept, float $slope): array
    {
        $entries = [];
        $date = new DateTimeImmutable($start);
        for ($d = 0; $d < $days; $d++) {
            $entries[] = new DebtEntry($date->modify("+{$d} days")->format('Y-m-d'), ($intercept + $slope * $d) * self::T);
        }
        return $entries;
    }

    public function testPerfectStraightLineGivesExactSlopeInterceptAndR2(): void
    {
        $line = TrendService::fit([[0, 3.0], [1, 5.0], [2, 7.0], [3, 9.0]]);

        self::assertSame(2.0, $line['slope']);
        self::assertSame(3.0, $line['intercept']);
        self::assertSame(1.0, $line['r2']);
    }

    public function testPerfectDailyLine(): void
    {
        $trend = TrendService::daily(self::line('2026-10-01', 30, 38.0, 0.006));

        self::assertEqualsWithDelta(0.006, $trend['slope_per_day'], 1e-12);
        self::assertEqualsWithDelta(38.0, $trend['intercept'], 1e-9);
        self::assertEqualsWithDelta(1.0, $trend['r2'], 1e-12);
        self::assertSame('2026-10-01', $trend['first_date']);
        self::assertSame('2026-10-30', $trend['last_date']);
        self::assertCount(30, $trend['fitted'], 'one fitted value per data point');
        self::assertSame(['2026-10-01', 38.0], $trend['fitted'][0]);
        self::assertSame(['2026-10-30', 38.174], $trend['fitted'][29]);
    }

    public function testFlatLine(): void
    {
        $trend = TrendService::daily(self::line('2026-10-01', 10, 36.5, 0.0));

        self::assertEqualsWithDelta(0.0, $trend['slope_per_day'], 1e-12);
        self::assertEqualsWithDelta(36.5, $trend['intercept'], 1e-9);
        self::assertSame(1.0, $trend['r2'], 'every point is on a flat line');
        foreach (array_merge($trend['fitted'], $trend['projection']) as $point) {
            self::assertSame(36.5, $point[1]);
        }
    }

    public function testFallingLine(): void
    {
        $trend = TrendService::daily(self::line('2026-10-01', 10, 30.0, -0.25));

        self::assertEqualsWithDelta(-0.25, $trend['slope_per_day'], 1e-12);
        self::assertEqualsWithDelta(1.0, $trend['r2'], 1e-12);
        $last = $trend['projection'][count($trend['projection']) - 1];
        self::assertLessThan($trend['fitted'][9][1], $last[1], 'the projection keeps falling');
    }

    public function testNoisyDataHasR2BelowOne(): void
    {
        $line = TrendService::fit([[0, 1.0], [1, 3.0], [2, 2.0], [3, 4.0]]);

        self::assertEqualsWithDelta(0.8, $line['slope'], 1e-12);
        self::assertEqualsWithDelta(1.3, $line['intercept'], 1e-12);
        self::assertEqualsWithDelta(0.64, $line['r2'], 1e-12);
    }

    public function testZeroOrOnePointGivesNoLine(): void
    {
        self::assertNull(TrendService::fit([]));
        self::assertNull(TrendService::fit([[5, 1.0]]));
        self::assertNull(TrendService::daily([]));
        self::assertNull(TrendService::daily([new DebtEntry('2026-11-09', 5.0 * self::T)]));
        self::assertNull(TrendService::series([[1, 0.0]]));
    }

    public function testPointsAllOnOneDayGiveNoLine(): void
    {
        self::assertNull(TrendService::fit([[3, 1.0], [3, 2.0]]));
    }

    public function testTwoPointsGiveTheLineThroughThem(): void
    {
        $trend = TrendService::daily([
            new DebtEntry('2026-11-02', 10.0 * self::T),
            new DebtEntry('2026-11-06', 12.0 * self::T),
        ]);

        self::assertEqualsWithDelta(0.5, $trend['slope_per_day'], 1e-12);
        self::assertEqualsWithDelta(10.0, $trend['intercept'], 1e-12);
        self::assertSame(1.0, $trend['r2']);
        self::assertSame([['2026-11-02', 10.0], ['2026-11-06', 12.0]], $trend['fitted']);
    }

    public function testNewestFirstGivesTheSameResultAsOldestFirst(): void
    {
        $entries = [
            new DebtEntry('2026-11-02', 100.0 * self::T),
            new DebtEntry('2026-11-03', 103.0 * self::T),
            new DebtEntry('2026-11-05', 101.0 * self::T),
            new DebtEntry('2026-11-09', 110.0 * self::T),
        ];

        self::assertSame(TrendService::daily($entries), TrendService::daily(array_reverse($entries)));
    }

    public function testWeekendGapsUseCalendarDaysNotListPosition(): void
    {
        // Mon, Tue, Wed, then next Mon: +1 trillion per CALENDAR day, so a perfect line.
        // By list position (0, 1, 2, 3) the slope would be 2.2 and the fit imperfect.
        $trend = TrendService::daily([
            new DebtEntry('2026-11-02', 100.0 * self::T),
            new DebtEntry('2026-11-03', 101.0 * self::T),
            new DebtEntry('2026-11-04', 102.0 * self::T),
            new DebtEntry('2026-11-09', 107.0 * self::T),
        ]);
        $byPosition = TrendService::fit([[0, 100.0], [1, 101.0], [2, 102.0], [3, 107.0]]);

        self::assertEqualsWithDelta(1.0, $trend['slope_per_day'], 1e-12);
        self::assertEqualsWithDelta(1.0, $trend['r2'], 1e-12);
        self::assertEqualsWithDelta(2.2, $byPosition['slope'], 1e-12);
        self::assertSame(['2026-11-09', 107.0], $trend['fitted'][3]);
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function projectionLengths(): array
    {
        return [
            '1 day range -> minimum 3 days' => [1, 3],
            '1 week range -> minimum 3 days' => [6, 3],
            '1 month range -> 25%, rounded up' => [30, 8],
            '1 year range -> 25%' => [364, 91],
            '4 years -> capped at 12 months' => [1460, 365],
            '30 years -> capped at 12 months' => [10957, 365],
        ];
    }

    #[DataProvider('projectionLengths')]
    public function testProjectionLength(int $rangeDays, int $expected): void
    {
        self::assertSame($expected, TrendService::projectionDays($rangeDays));
    }

    public function testProjectionStartsTheDayAfterTheLastDateAndFollowsTheLine(): void
    {
        $trend = TrendService::daily(self::line('2026-10-01', 31, 38.0, 0.01)); // range 30 days -> 8 days

        self::assertSame(8, $trend['projection_days']);
        self::assertCount(8, $trend['projection']);
        self::assertSame(['2026-11-01', 38.31], $trend['projection'][0]);
        self::assertSame(['2026-11-08', 38.38], $trend['projection'][7]);
    }

    public function testProjectionIsCappedAtTwelveMonthsOnLongRanges(): void
    {
        $trend = TrendService::daily([
            new DebtEntry('2000-01-03', 5.0 * self::T),
            new DebtEntry('2026-11-10', 38.0 * self::T),
        ]);

        self::assertSame(365, $trend['projection_days']);
        self::assertSame('2026-11-11', $trend['projection'][0][0]);
        self::assertSame('2027-11-10', $trend['projection'][364][0]);
    }

    public function testDailyChartCarriesTrendAndFlaggedProjection(): void
    {
        $chart = ChartData::daily(self::line('2026-10-01', 31, 38.0, 0.01));

        self::assertSame(['debt', 'trend', 'projection'], array_column($chart['series'], 'key'));
        [$debt, $trend, $projection] = $chart['series'];
        self::assertSame(array_column($debt['points'], 0), array_column($trend['points'], 0), 'trend points line up 1:1 with the data');
        self::assertSame(0.01, $trend['slope_per_day']);
        self::assertSame(1.0, $trend['r2']);
        self::assertArrayNotHasKey('projection', $trend);
        self::assertTrue($projection['projection']);
        self::assertGreaterThan('2026-10-31', $projection['points'][0][0], 'projection starts after the last data day');
        self::assertIsFloat($projection['points'][0][1], 'numbers, not strings');
    }

    public function testDailyChartWithOneDayHasNoTrend(): void
    {
        $chart = ChartData::daily([new DebtEntry('2026-11-09', 38.0 * self::T)]);

        self::assertSame(['debt'], array_column($chart['series'], 'key'));
    }

    public function testCompareSeriesEachGetTheirOwnTrendWithoutProjection(): void
    {
        $chart = ChartData::compare([
            ['label' => 'A (2017-2021)', 'president' => 'A', 'term_starts' => [], 'data' => [[1, 0.0], [2, 0.5], [3, 1.0]]],
            ['label' => 'B (2021-2025)', 'president' => 'B', 'term_starts' => [], 'data' => [[1, 0.0], [2, -0.2], [3, -0.4]]],
            ['label' => 'C (2029-2033)', 'president' => 'C', 'term_starts' => [], 'data' => [[1, 0.0]]],
        ]);

        self::assertSame(0.5, $chart['series'][0]['trend']['slope_per_month']);
        self::assertSame([[1, 0.0], [2, 0.5], [3, 1.0]], $chart['series'][0]['trend']['points']);
        self::assertSame(-0.2, $chart['series'][1]['trend']['slope_per_month']);
        self::assertNull($chart['series'][2]['trend'], 'one month: no line');
        self::assertArrayNotHasKey('projection', $chart['series'][0]['trend']);
    }

    public function testContinuousTwoTermSeriesIsFittedAsOneRun(): void
    {
        // Months 1-3 then 4-6 (second term begins at 4, carried on top of the first).
        $data = [[1, 0.0], [2, 1.0], [3, 2.0], [4, 3.0], [5, 4.0], [6, 5.0]];
        $chart = ChartData::compare([
            ['label' => 'A (2017-2021, 2025-2029)', 'president' => 'A', 'term_starts' => [4], 'data' => $data],
        ]);

        $trend = $chart['series'][0]['trend'];
        self::assertSame(1.0, $trend['slope_per_month']);
        self::assertSame(1.0, $trend['r2']);
        self::assertCount(6, $trend['points'], 'one line across both terms');
        self::assertSame($data, $trend['points']);
    }

    public function testSlopePerDayMatchesTheDailyTrendWithoutAProjection(): void
    {
        $entries = array_reverse(self::line('2020-01-01', 2000, 20.0, 0.004));

        self::assertSame(TrendService::daily($entries)['slope_per_day'], TrendService::slopePerDay($entries));
        self::assertEqualsWithDelta(0.004, TrendService::slopePerDay($entries), 1e-12);
        self::assertNull(TrendService::slopePerDay(self::line('2020-01-01', 1, 20.0, 0.004)));
        self::assertNull(TrendService::slopePerDay([]));
    }

    public function testSeriesFittedPointsAreSortedByMonthWhateverTheInputOrder(): void
    {
        $trend = TrendService::series([[3, 6.0], [1, 2.0], [2, 4.0]]);

        self::assertSame([[1, 2.0], [2, 4.0], [3, 6.0]], $trend['fitted']);
    }
}
