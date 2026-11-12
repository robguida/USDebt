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

        self::assertSame(['debt', 'trend', 'projection', 'curve', 'curve_projection'], array_column($chart['series'], 'key'));
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

    /* ---------- growth curve ---------- */

    /**
     * Steady percent growth: value = $start * (1 + $pct/100)^(t/365), t in calendar days,
     * one entry every $step days.
     *
     * @return list<DebtEntry>
     */
    private static function compound(string $start, int $days, int $step, float $startValue, float $pct): array
    {
        $entries = [];
        $date = new DateTimeImmutable($start);
        for ($d = 0; $d < $days; $d += $step) {
            $entries[] = new DebtEntry($date->modify("+{$d} days")->format('Y-m-d'), $startValue * (1 + $pct / 100) ** ($d / 365) * self::T);
        }
        return $entries;
    }

    /** @return list<DebtEntry> U.S. Treasury data: first reported day of each month, Jan 2000 to Nov 9, 2026. */
    private static function liveShape2000ToToday(): array
    {
        $doc = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/debt_monthly_2000_2026.json'), true, 512, JSON_THROW_ON_ERROR);
        return array_map(static fn (array $row): DebtEntry => new DebtEntry($row[0], (float) $row[1]), $doc['rows']);
    }

    public function testPerfectFivePercentCompoundGrowthIsFoundExactly(): void
    {
        $entries = self::compound('2020-01-01', 5 * 365, 7, 30.0, 5.0);
        $curve = TrendService::growth($entries);

        self::assertEqualsWithDelta(5.0, $curve['annual_growth_pct'], 1e-9);
        self::assertEqualsWithDelta(log(1.05) / 365, $curve['rate_per_day'], 1e-15);
        self::assertEqualsWithDelta(30.0, $curve['start_value'], 1e-9);
        self::assertEqualsWithDelta(1.0, $curve['r2'], 1e-12);
        self::assertSame(0, $curve['skipped_points']);
        self::assertCount(count($entries), $curve['fitted'], 'one fitted value per data point');
        foreach ($entries as $i => $entry) {
            self::assertSame($entry->effectiveDate, $curve['fitted'][$i][0]);
            self::assertEqualsWithDelta($entry->totalDebt / self::T, $curve['fitted'][$i][1], 1e-6, 'the curve goes through every point');
        }
        // The projection keeps compounding at 5% a year: 365 days on, exactly 5% more than the curve on the last day.
        $lastDay = (new DateTimeImmutable('2020-01-01'))->modify('+' . (count($entries) - 1) * 7 . ' days')->format('Y-m-d');
        self::assertSame($lastDay, $curve['last_date']);
        $lastFitted = $curve['fitted'][count($entries) - 1][1];
        self::assertSame(365, $curve['projection_days']);
        self::assertEqualsWithDelta($lastFitted * 1.05, $curve['projection'][364][1], 1e-5);
    }

    public function testGrowthCurveOnStraightLineDataIsStillSane(): void
    {
        // +6 billion a day for a year, from 38 trillion: a straight line, not a curve.
        $entries = self::line('2025-11-01', 366, 38.0, 0.006);
        $curve = TrendService::growth($entries);
        $straight = TrendService::daily($entries);

        // 365 * 0.006 = 2.19 trillion on about 39.1 on average: roughly 5.6% a year.
        self::assertGreaterThan(5.0, $curve['annual_growth_pct']);
        self::assertLessThan(6.5, $curve['annual_growth_pct']);
        self::assertGreaterThan(0.999, $curve['r2'], 'a gentle curve hugs a straight line over one year');
        self::assertLessThanOrEqual(1.0, $curve['r2']);
        foreach ($entries as $i => $entry) {
            self::assertEqualsWithDelta($entry->totalDebt / self::T, $curve['fitted'][$i][1], 0.05, 'within $50 billion of every point');
        }
        $previous = $curve['fitted'][count($entries) - 1][1];
        foreach ($curve['projection'] as [, $value]) {
            self::assertGreaterThan($previous, $value, 'the curve keeps rising');
            $previous = $value;
        }
        // Compounding bends upward, so it ends a little above the straight projection, not far above it.
        $curveEnd = $curve['projection'][count($curve['projection']) - 1][1];
        $straightEnd = $straight['projection'][count($straight['projection']) - 1][1];
        self::assertGreaterThan($straightEnd, $curveEnd);
        self::assertLessThan($straightEnd + 0.5, $curveEnd);
    }

    public function testGrowthCurveNeedsAtLeastTwoDays(): void
    {
        self::assertNull(TrendService::growth([]));
        self::assertNull(TrendService::growth([new DebtEntry('2026-11-09', 38.0 * self::T)]));
        self::assertNull(TrendService::growthPctPerYear([]));
        self::assertNull(TrendService::growthPctPerYear([new DebtEntry('2026-11-09', 38.0 * self::T)]));
    }

    public function testACurveThatWouldBeInfiniteIsLeftOutNotAnError(): void
    {
        // A tiny value then a normal one the next day: the yearly % and the
        // projection overflow to infinity. No curve, and the straight line stays.
        $entries = [
            new DebtEntry('2026-01-01', 1e-300),
            new DebtEntry('2026-01-02', 10.0 * self::T),
        ];

        self::assertNull(TrendService::growth($entries));
        self::assertNull(TrendService::growthPctPerYear($entries));
        self::assertNotNull(TrendService::daily($entries));

        $chart = ChartData::daily($entries);
        self::assertSame(['debt', 'trend', 'projection'], array_column($chart['series'], 'key'));
        json_encode($chart, JSON_THROW_ON_ERROR); // every number can go to the page
    }

    public function testTwoDaysGiveTheCurveThroughThem(): void
    {
        // Doubles in 365 days: +100% a year.
        $curve = TrendService::growth([
            new DebtEntry('2025-01-01', 10.0 * self::T),
            new DebtEntry('2026-01-01', 20.0 * self::T),
        ]);

        self::assertEqualsWithDelta(100.0, $curve['annual_growth_pct'], 1e-9);
        self::assertEqualsWithDelta(1.0, $curve['r2'], 1e-12);
        self::assertSame([['2025-01-01', 10.0], ['2026-01-01', 20.0]], $curve['fitted']);
    }

    public function testGrowthCurveLeavesOutDaysAtOrBelowZeroAndKeepsTheRest(): void
    {
        $entries = [
            new DebtEntry('2026-01-01', 10.0 * self::T),
            new DebtEntry('2026-01-02', 0.0),
            new DebtEntry('2026-01-03', -5.0 * self::T),
            new DebtEntry('2026-01-04', 10.0 * self::T),
            new DebtEntry('2026-01-05', NAN),
        ];
        $curve = TrendService::growth($entries);

        self::assertSame(3, $curve['skipped_points']);
        self::assertEqualsWithDelta(0.0, $curve['annual_growth_pct'], 1e-9, 'fitted over the two good days only');
        self::assertSame(
            ['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05'],
            array_column($curve['fitted'], 0),
            'every day still gets a value on the curve, so it lines up with the chart'
        );
        self::assertSame([10.0], array_values(array_unique(array_column($curve['fitted'], 1))));
        self::assertEqualsWithDelta(0.0, TrendService::growthPctPerYear($entries), 1e-9, 'Stats uses the same curve');

        // Fewer than 2 good days: no curve.
        self::assertNull(TrendService::growth([new DebtEntry('2026-01-01', 10.0 * self::T), new DebtEntry('2026-01-02', 0.0)]));
        self::assertNull(TrendService::growth([new DebtEntry('2026-01-01', -1.0), new DebtEntry('2026-01-02', -2.0)]));
    }

    /** @return array<string, array{0: int}> */
    public static function rangeLengths(): array
    {
        return ['1 week' => [8], '1 month' => [31], '1 year' => [365], '4 years' => [1461]];
    }

    #[DataProvider('rangeLengths')]
    public function testGrowthProjectionUsesTheSameDaysAsTheStraightProjection(int $days): void
    {
        $entries = self::compound('2022-03-15', $days, 1, 25.0, 4.0);
        $curve = TrendService::growth($entries);
        $straight = TrendService::daily($entries);

        self::assertSame($straight['projection_days'], $curve['projection_days']);
        self::assertSame(array_column($straight['projection'], 0), array_column($curve['projection'], 0));
        self::assertSame($straight['last_date'], $curve['last_date']);
        self::assertGreaterThan($curve['last_date'], $curve['projection'][0][0], 'starts the day after the last data day');
    }

    public function testGrowthCurveNewestFirstGivesTheSameResultAsOldestFirst(): void
    {
        $entries = [
            new DebtEntry('2026-11-02', 100.0 * self::T),
            new DebtEntry('2026-11-03', 103.0 * self::T),
            new DebtEntry('2026-11-05', 101.0 * self::T),
            new DebtEntry('2026-11-09', 110.0 * self::T),
        ];

        self::assertSame(TrendService::growth($entries), TrendService::growth(array_reverse($entries)));
        self::assertSame(TrendService::growthPctPerYear($entries), TrendService::growthPctPerYear(array_reverse($entries)));
    }

    /**
     * Real shape, Jan 2000 to Nov 9, 2026 (one day a month). Numbers when written:
     * last value 40.56 trillion; straight projection 34.94 rising to 36.21 trillion
     * (below today); growth curve about 8.05% a year, projection 42.58 rising to
     * 46.00 trillion by Nov 9, 2027 (above today). r2: straight 0.948, curve 0.991.
     */
    public function testOnTwoThousandToTodayTheCurveProjectsAboveTodayAndTheStraightLineBelow(): void
    {
        $entries = self::liveShape2000ToToday();
        $today = $entries[count($entries) - 1]->totalDebt / self::T;
        $straight = TrendService::daily($entries);
        $curve = TrendService::growth($entries);

        self::assertSame('2026-11-09', $curve['last_date']);
        self::assertEqualsWithDelta(40.5598, $today, 1e-4);
        self::assertSame(365, $curve['projection_days']);

        foreach ($curve['projection'] as [$date, $value]) {
            self::assertGreaterThanOrEqual($today, $value, "growth projection on {$date} is not below today's debt");
        }
        self::assertLessThan($today, $straight['projection'][364][1], 'the straight projection ends below today');

        self::assertEqualsWithDelta(34.94, $straight['projection'][0][1], 0.01);
        self::assertEqualsWithDelta(36.21, $straight['projection'][364][1], 0.01);
        self::assertEqualsWithDelta(8.05, $curve['annual_growth_pct'], 0.01);
        self::assertEqualsWithDelta(42.58, $curve['projection'][0][1], 0.01);
        self::assertEqualsWithDelta(46.00, $curve['projection'][364][1], 0.01);
        self::assertGreaterThan($straight['r2'], $curve['r2'], 'the curve follows the long range more closely');
    }

    public function testDailyChartCarriesTheGrowthCurveNextToTheStraightLine(): void
    {
        $entries = self::compound('2026-01-01', 300, 1, 36.0, 6.0);
        $chart = ChartData::daily($entries);

        self::assertSame(['debt', 'trend', 'projection', 'curve', 'curve_projection'], array_column($chart['series'], 'key'));
        [$debt, , $projection, $curve, $curveProjection] = $chart['series'];
        self::assertSame('Trend (growth curve)', $curve['label']);
        self::assertSame('Growth projection', $curveProjection['label']);
        self::assertSame(array_column($debt['points'], 0), array_column($curve['points'], 0), 'curve points line up 1:1 with the data');
        self::assertSame(6.0, $curve['growth_pct_per_year']);
        self::assertSame(1.0, $curve['r2']);
        self::assertArrayNotHasKey('projection', $curve);
        self::assertTrue($curveProjection['projection']);
        self::assertSame(array_column($projection['points'], 0), array_column($curveProjection['points'], 0), 'same projection days');
        self::assertIsFloat($curveProjection['points'][0][1], 'numbers, not strings');
    }

    public function testCompareChartHasNoGrowthCurve(): void
    {
        $chart = ChartData::compare([
            ['label' => 'A (2017-2021)', 'president' => 'A', 'term_starts' => [], 'data' => [[1, 0.0], [2, 0.5], [3, 1.0]]],
        ]);

        self::assertSame(['key', 'label', 'term_starts', 'points', 'trend'], array_keys($chart['series'][0]));
        self::assertSame(['slope_per_month', 'r2', 'points'], array_keys($chart['series'][0]['trend']));
    }

    public function testSeriesFittedPointsAreSortedByMonthWhateverTheInputOrder(): void
    {
        $trend = TrendService::series([[3, 6.0], [1, 2.0], [2, 4.0]]);

        self::assertSame([[1, 2.0], [2, 4.0], [3, 6.0]], $trend['fitted']);
    }
}
