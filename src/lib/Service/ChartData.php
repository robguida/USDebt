<?php

declare(strict_types=1);

namespace USDebt\Service;

use USDebt\Model\DebtEntry;
use USDebt\Model\Per;

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
 *
 * Units ("Your Share"): both shapes carry "per" (total, person or household)
 * and "unit". The total view is in trillions of dollars ("trillion_usd"),
 * as before. Per person and per household are in plain dollars ("usd"):
 * each day's debt divided by that day's people or households, and every
 * trend line, curve, slope and projection is fitted on that scaled series,
 * so the lines sit on the plotted data. Slopes are in the same unit.
 */
final class ChartData
{
    /**
     * Date-range chart.
     *
     * @param list<DebtEntry> $entries  Oldest first. For per person or per
     *                                  household: already scaled (PopulationService::scale).
     * @return array{type: string, per: string, unit: string, series: list<array<string, mixed>>}
     *   Every series has key, label and points = [ISO date, value in the unit].
     *   "trend" also has slope_per_day (unit per day) and r2.
     *   "curve" also has growth_pct_per_year (percent) and r2 (on the plotted scale).
     */
    public static function daily(array $entries, Per $per = Per::Total): array
    {
        $unit = $per->unitDollars();
        $points = [];
        foreach ($entries as $entry) {
            $points[] = [$entry->effectiveDate, round($entry->totalDebt / $unit, $per->isTotal() ? 6 : 2)];
        }
        // The words come from ShareText: "label" (with the unit) is the legend's,
        // "name" (without it) is the tooltip's.
        $debt = ShareText::debtSeries($per);
        $series = [
            ['key' => 'debt', 'label' => $debt['label'], 'name' => $debt['name'], 'points' => $points],
        ];

        $trend = TrendService::daily($entries, $unit);
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

        $curve = TrendService::growth($entries, $unit);
        if ($curve !== null) {
            $series[] = [
                'key' => 'curve',
                'label' => 'Trend (growth curve)',
                // Not rounded here: the page rounds once, when it shows the percent.
                'growth_pct_per_year' => $curve['annual_growth_pct'],
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

        return ['type' => 'daily', 'per' => $per->value, 'unit' => $per->unit(), 'series' => $series];
    }

    /**
     * Compare chart, from CompareService::buildSeries() (built for the same mode).
     *
     * @param list<array{label: string, president: string, term_starts: list<int>, data: list<array{0: int, 1: float}>}> $series
     * @return array{type: string, per: string, unit: string, series: list<array{key: string, label: string, term_starts: list<int>, points: list<array{0: int, 1: float}>,
     *               trend: array{slope_per_month: float, r2: float, points: list<array{0: int, 1: float}>}|null}>}
     *   points = [month in office, change in the unit]; term_starts = months where a later term begins.
     *   trend = straight line over this series' own points (null with fewer than 2 months).
     */
    public static function compare(array $series, Per $per = Per::Total): array
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
        return ['type' => 'compare', 'per' => $per->value, 'unit' => $per->unit(), 'series' => $out];
    }

    /** A chart value (or slope) back to dollars: times 1e12 for "trillion_usd", as-is for "usd". */
    public static function toDollars(array $chart, float $value): float
    {
        return ($chart['unit'] ?? Per::UNIT_TRILLION_USD) === Per::UNIT_USD ? $value : $value * 1_000_000_000_000;
    }
}
