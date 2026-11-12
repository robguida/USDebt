<?php

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;
use USDebt\Model\DebtEntry;

/**
 * Numbers for the Stats tab. Returns null when there is no data, so the view
 * shows a message instead of dividing by zero.
 *
 * average_per_day = change in debt / calendar days between the first and the
 * last reported day (not / number of reported days).
 *
 * trend_per_day = slope of the straight trend line (TrendService), in dollars
 * per calendar day; null when the range has fewer than 2 days.
 *
 * growth_pct_per_year = yearly growth of the growth curve (TrendService::growth),
 * in percent; null when there is no curve.
 *
 * Per person and per household views pass their scaled entries
 * (PopulationService::scale: each day's debt divided by that day's people or
 * households) with $unit = 1.0. Every number is then per person (or per
 * household), in dollars: delta = D_end/P_end - D_start/P_start, and the
 * trend is fitted on the scaled series, the same line the chart draws.
 */
final class StatsCalculator
{
    private const TRILLION = 1_000_000_000_000;

    /**
     * @param list<DebtEntry> $entries  Oldest first.
     * @param float           $unit     Dollars per chart unit the trend is fitted in: 1e12 (total) or 1.0 (per person or household).
     * @return array{first_debt: float, last_debt: float, delta: float, average_per_day: float,
     *               working_days: int, total_days: int, days_above_start: int, pct_above_start: float,
     *               days_below_start: int, pct_below_start: float,
     *               trend_per_day: float|null, growth_pct_per_year: float|null}|null
     */
    public static function summarize(array $entries, float $unit = self::TRILLION): ?array
    {
        $workingDays = count($entries);
        if ($workingDays === 0) {
            return null;
        }
        $first = $entries[0];
        $last = $entries[$workingDays - 1];
        $delta = $last->totalDebt - $first->totalDebt;
        // Calendar days from the first to the last reported day (weekends and holidays count).
        $totalDays = (int) (new DateTimeImmutable($first->effectiveDate))->diff(new DateTimeImmutable($last->effectiveDate))->days;

        $trendSlope = TrendService::slopePerDay($entries, $unit);
        $growthPct = TrendService::growthPctPerYear($entries, $unit);

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
            'average_per_day' => $totalDays > 0 ? round($delta / $totalDays, 2) : 0.0,
            'working_days' => $workingDays,
            'total_days' => $totalDays,
            'days_above_start' => $above,
            'pct_above_start' => round($above / $workingDays * 100, 2),
            'days_below_start' => $below,
            'pct_below_start' => round($below / $workingDays * 100, 2),
            'trend_per_day' => $trendSlope === null ? null : round($trendSlope * $unit, 2),
            'growth_pct_per_year' => $growthPct === null ? null : round($growthPct, 2),
        ];
    }
}
