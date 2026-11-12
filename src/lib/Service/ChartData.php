<?php

declare(strict_types=1);

namespace USDebt\Service;

use USDebt\Model\DebtEntry;

/**
 * JSON-ready arrays for the charts. Values are numbers, not strings.
 *
 * Both shapes hold a list of series, so the page script draws each entry as
 * one line. The straight trend line (TrendService) is added here:
 * - Date-range chart: two extra series, key "trend" (fitted over the shown
 *   days) and key "projection" (the same line carried past the last day,
 *   flagged "projection": true). Left out when there are fewer than 2 days.
 *   Next to it, the growth curve (TrendService::growth): key "curve" (fitted
 *   over the shown days) and key "curve_projection" (carried past the last
 *   day over the same days as "projection", flagged "projection": true).
 *   Left out when there are fewer than 2 days with a value above zero.
 * - Compare chart: each president's series carries its own "trend" (or null),
 *   fitted over that series' months in office. No projection, and no growth
 *   curve (one extra line per term would double the lines on that chart).
 */
final class ChartData
{
    private const TRILLION = 1_000_000_000_000;

    /**
     * Date-range chart.
     *
     * @param list<DebtEntry> $entries  Oldest first.
     * @return array{type: string, series: list<array<string, mixed>>}
     *   Every series has key, label and points = [ISO date, debt in trillions USD].
     *   "trend" also has slope_per_day (trillions per day) and r2.
     *   "curve" also has growth_pct_per_year (percent) and r2 (on the dollar scale).
     */
    public static function daily(array $entries): array
    {
        $points = [];
        foreach ($entries as $entry) {
            $points[] = [$entry->effectiveDate, round($entry->totalDebt / self::TRILLION, 6)];
        }
        $series = [
            ['key' => 'debt', 'label' => 'Total debt (trillions USD)', 'points' => $points],
        ];

        $trend = TrendService::daily($entries);
        if ($trend !== null) {
            $series[] = [
                'key' => 'trend',
                'label' => 'Trend (straight line)',
                'slope_per_day' => round($trend['slope_per_day'], 9),
                'r2' => round($trend['r2'], 4),
                'points' => $trend['fitted'],
            ];
            $series[] = [
                'key' => 'projection',
                'label' => 'Projection if the trend continues',
                'projection' => true,
                'points' => $trend['projection'],
            ];
        }

        $curve = TrendService::growth($entries);
        if ($curve !== null) {
            $series[] = [
                'key' => 'curve',
                'label' => 'Trend (growth curve)',
                'growth_pct_per_year' => round($curve['annual_growth_pct'], 4),
                'r2' => round($curve['r2'], 4),
                'points' => $curve['fitted'],
            ];
            $series[] = [
                'key' => 'curve_projection',
                'label' => 'Growth projection',
                'projection' => true,
                'points' => $curve['projection'],
            ];
        }

        return ['type' => 'daily', 'series' => $series];
    }

    /**
     * Compare chart, from CompareService::buildSeries().
     *
     * @param list<array{label: string, president: string, term_starts: list<int>, data: list<array{0: int, 1: float}>}> $series
     * @return array{type: string, series: list<array{key: string, label: string, term_starts: list<int>, points: list<array{0: int, 1: float}>,
     *               trend: array{slope_per_month: float, r2: float, points: list<array{0: int, 1: float}>}|null}>}
     *   points = [month in office, change in trillions USD]; term_starts = months where a later term begins.
     *   trend = straight line over this series' own points (null with fewer than 2 months).
     */
    public static function compare(array $series): array
    {
        $out = [];
        foreach ($series as $i => $s) {
            $trend = TrendService::series($s['data']);
            $out[] = [
                'key' => 'term' . ($i + 1),
                'label' => $s['label'],
                'term_starts' => $s['term_starts'],
                'points' => $s['data'],
                'trend' => $trend === null ? null : [
                    'slope_per_month' => round($trend['slope_per_month'], 6),
                    'r2' => round($trend['r2'], 4),
                    'points' => $trend['fitted'],
                ],
            ];
        }
        return ['type' => 'compare', 'series' => $out];
    }
}
