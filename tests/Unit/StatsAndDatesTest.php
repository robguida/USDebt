<?php

declare(strict_types=1);

namespace USDebt\Tests\Unit;

use PHPUnit\Framework\TestCase;
use USDebt\Model\DebtEntry;
use USDebt\Service\ChartData;
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
    }

    public function testStatsOverARange(): void
    {
        $stats = StatsCalculator::summarize([
            new DebtEntry('2026-11-02', 100.0),
            new DebtEntry('2026-11-03', 90.0),
            new DebtEntry('2026-11-04', 130.0),
            new DebtEntry('2026-11-06', 140.0),
        ]);

        self::assertSame(100.0, $stats['first_debt']);
        self::assertSame(140.0, $stats['last_debt']);
        self::assertSame(40.0, $stats['delta']);
        self::assertSame(10.0, $stats['average_per_day']);
        self::assertSame(4, $stats['total_days']);
        self::assertSame(2, $stats['days_above_start']);
        self::assertSame(50.0, $stats['pct_above_start']);
        self::assertSame(1, $stats['days_below_start']);
        self::assertSame(25.0, $stats['pct_below_start']);
    }

    public function testChartValuesAreNumbersInTrillions(): void
    {
        $chart = ChartData::daily([new DebtEntry('2026-11-09', 40559796589243.37)]);

        self::assertSame(['11/9/26'], $chart['labels']);
        self::assertSame([40.559797], $chart['values']);
        self::assertSame('[40.559797]', json_encode($chart['values']));
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
}
