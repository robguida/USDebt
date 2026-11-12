<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use USDebt\Controller\USDebtController;
use USDebt\Model\DebtEntry;
use USDebt\Service\ChartData;
use USDebt\Service\Html;
use USDebt\Service\StatsCalculator;
use USDebt\Service\ValidateDateTime;

final class StatsAndDatesTest extends TestCase
{
    public function testEmptyDataGivesNoStatsInsteadOfDividingByZero(): void
    {
        self::assertNull(StatsCalculator::summarize([]));
    }

    public function testSingleDayStats(): void
    {
        $stats = StatsCalculator::summarize([new DebtEntry('2026-11-09', 5.0)]);

        self::assertSame(0.0, $stats['delta']);
        self::assertSame(0.0, $stats['average_per_day']);
        self::assertSame(1, $stats['working_days']);
        self::assertSame(0, $stats['total_days']);
        self::assertNull($stats['trend_per_day'], 'one day: no trend line');
        self::assertNull($stats['growth_pct_per_year'], 'one day: no growth curve');
    }

    public function testStatsGrowthCurveIsTheYearlyPercent(): void
    {
        // Exactly 5% a year, compounded daily over calendar days.
        $entries = [];
        foreach (['2024-01-01', '2024-06-03', '2025-01-01', '2025-09-15'] as $date) {
            $t = (new DateTimeImmutable('2024-01-01'))->diff(new DateTimeImmutable($date))->days;
            $entries[] = new DebtEntry($date, 30_000_000_000_000.0 * 1.05 ** ($t / 365));
        }

        self::assertSame(5.0, StatsCalculator::summarize($entries)['growth_pct_per_year']);
    }

    public function testStatsTrendIsTheStraightLineSlopeInDollarsPerDay(): void
    {
        // +6.04 billion every calendar day, with a weekend gap.
        $stats = StatsCalculator::summarize([
            new DebtEntry('2026-11-06', 38_000_000_000_000.0),
            new DebtEntry('2026-11-09', 38_018_120_000_000.0),
            new DebtEntry('2026-11-10', 38_024_160_000_000.0),
        ]);

        self::assertEqualsWithDelta(6_040_000_000.0, $stats['trend_per_day'], 0.01);
    }

    public function testStatsOverARange(): void
    {
        // 4 reported days spread over 8 calendar days (a weekend and a gap in between).
        $stats = StatsCalculator::summarize([
            new DebtEntry('2026-11-02', 100.0),
            new DebtEntry('2026-11-03', 90.0),
            new DebtEntry('2026-11-04', 130.0),
            new DebtEntry('2026-11-10', 140.0),
        ]);

        self::assertSame(100.0, $stats['first_debt']);
        self::assertSame(140.0, $stats['last_debt']);
        self::assertSame(40.0, $stats['delta']);
        self::assertSame(4, $stats['working_days']);
        self::assertSame(8, $stats['total_days']);
        self::assertSame(5.0, $stats['average_per_day'], 'per calendar day: 40 / 8, not 40 / 4');
        self::assertSame(2, $stats['days_above_start']);
        self::assertSame(50.0, $stats['pct_above_start']);
        self::assertSame(1, $stats['days_below_start']);
        self::assertSame(25.0, $stats['pct_below_start']);
    }

    public function testChartValuesAreNumbersInTrillions(): void
    {
        $chart = ChartData::daily([new DebtEntry('2026-11-09', 40559796589243.37)]);

        self::assertSame('daily', $chart['type']);
        self::assertSame('debt', $chart['series'][0]['key']);
        self::assertSame([['2026-11-09', 40.559797]], $chart['series'][0]['points']);
        self::assertSame('[["2026-11-09",40.559797]]', json_encode($chart['series'][0]['points']));
    }

    public function testCompareChartDataKeepsTermStarts(): void
    {
        $chart = ChartData::compare([
            ['label' => 'A (2017-2021, 2025-2029)', 'president' => 'A', 'term_starts' => [49], 'data' => [[1, 0.0], [49, 7.5]]],
            ['label' => 'B (2021-2025)', 'president' => 'B', 'term_starts' => [], 'data' => [[1, 0.0]]],
        ]);

        self::assertSame('compare', $chart['type']);
        self::assertSame(['term1', 'term2'], array_column($chart['series'], 'key'));
        self::assertSame([49], $chart['series'][0]['term_starts']);
        self::assertSame([[1, 0.0], [49, 7.5]], $chart['series'][0]['points']);
        self::assertArrayNotHasKey('color', $chart['series'][0], 'colors come from the stylesheet, not the data');
    }

    public function testCompactMoney(): void
    {
        self::assertSame('$38.12 trillion', Html::compactMoney(38_123_456_789_012.0));
        self::assertSame('+$6.04 billion', Html::compactMoney(6_041_000_000.0, true));
        self::assertSame('-$1.50 million', Html::compactMoney(-1_500_000.0, true));
        self::assertSame('+$950.00', Html::compactMoney(950.0, true));
        self::assertSame('$0.00', Html::compactMoney(0.0, true));
    }

    public function testStrictDateValidation(): void
    {
        self::assertTrue(ValidateDateTime::isValid('2024-02-29'));
        self::assertFalse(ValidateDateTime::isValid('2023-02-29'));
        self::assertFalse(ValidateDateTime::isValid('2026-13-01'));
        self::assertFalse(ValidateDateTime::isValid('2026-1-1'));
        self::assertFalse(ValidateDateTime::isValid('<script>'));
        self::assertFalse(ValidateDateTime::isValid(''));
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function monthsBack(): array
    {
        return [
            'Mar 31 -> Feb 28' => ['2026-03-31', 1, '2026-02-28'],
            'Mar 31 -> Feb 29 in a leap year' => ['2028-03-31', 1, '2028-02-29'],
            'Jan 31 -> Dec 31 of the year before' => ['2026-01-31', 1, '2025-12-31'],
            'May 31 -> Apr 30' => ['2026-05-31', 1, '2026-04-30'],
            'leap Feb 29 -> Jan 29' => ['2028-02-29', 1, '2028-01-29'],
            'leap Feb 29, 1 year -> Feb 28' => ['2028-02-29', 12, '2027-02-28'],
            'leap Feb 29, 4 years -> Feb 29' => ['2028-02-29', 48, '2024-02-29'],
            'leap Feb 29, 5 years -> Feb 28' => ['2028-02-29', 60, '2023-02-28'],
            'mid-month is unchanged' => ['2026-11-12', 1, '2026-10-12'],
            'one year back' => ['2026-11-12', 12, '2025-11-12'],
        ];
    }

    #[DataProvider('monthsBack')]
    public function testMonthsBeforeNeverRollsIntoTheNextMonth(string $from, int $months, string $expected): void
    {
        $zone = new DateTimeZone('America/New_York');
        $result = USDebtController::monthsBefore(new DateTimeImmutable($from, $zone), $months);

        self::assertSame($expected, $result->format('Y-m-d'));
        self::assertSame('00:00:00', $result->format('H:i:s'), 'the time of day is kept');
        self::assertSame('America/New_York', $result->getTimezone()->getName());
    }
}
