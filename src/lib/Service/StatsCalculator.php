<?php

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;
use USDebt\Model\DebtEntry;

/**
 * Numbers for the Stats tab. Returns null when there is no data, so the view
 * shows a message instead of dividing by zero.
 */
final class StatsCalculator
{
    /**
     * @param list<DebtEntry> $entries  Oldest first.
     * @return array{first_debt: float, last_debt: float, delta: float, average_per_day: float,
     *               working_days: int, total_days: int, days_above_start: int, pct_above_start: float,
     *               days_below_start: int, pct_below_start: float}|null
     */
    public static function summarize(array $entries): ?array
    {
        $workingDays = count($entries);
        if ($workingDays === 0) {
            return null;
        }
        $first = $entries[0];
        $last = $entries[$workingDays - 1];
        $delta = $last->totalDebt - $first->totalDebt;

        $above = 0;
        $below = 0;
        foreach ($entries as $entry) {
            if ($entry->totalDebt > $first->totalDebt) {
                $above++;
            } elseif ($entry->totalDebt < $first->totalDebt) {
                $below++;
            }
        }

        return [
            'first_debt' => $first->totalDebt,
            'last_debt' => $last->totalDebt,
            'delta' => $delta,
            'average_per_day' => round($delta / $workingDays, 2),
            'working_days' => $workingDays,
            'total_days' => (new DateTimeImmutable($first->effectiveDate))->diff(new DateTimeImmutable($last->effectiveDate))->days,
            'days_above_start' => $above,
            'pct_above_start' => round($above / $workingDays * 100, 2),
            'days_below_start' => $below,
            'pct_below_start' => round($below / $workingDays * 100, 2),
        ];
    }
}
