<?php

declare(strict_types=1);

namespace USDebt\Service;

use USDebt\Model\DebtEntry;

/**
 * JSON-ready arrays for the charts. Values are numbers, not strings.
 *
 * Both shapes hold a list of series, so a later line (for example a trend
 * line) can be added as one more entry without changing the page script.
 */
final class ChartData
{
    private const TRILLION = 1_000_000_000_000;

    /**
     * Date-range chart.
     *
     * @param list<DebtEntry> $entries  Oldest first.
     * @return array{type: string, series: list<array{key: string, label: string, points: list<array{0: string, 1: float}>}>}
     *   points = [ISO date, debt in trillions USD].
     */
    public static function daily(array $entries): array
    {
        $points = [];
        foreach ($entries as $entry) {
            $points[] = [$entry->effectiveDate, round($entry->totalDebt / self::TRILLION, 6)];
        }
        return [
            'type' => 'daily',
            'series' => [
                ['key' => 'debt', 'label' => 'Total debt (trillions USD)', 'points' => $points],
            ],
        ];
    }

    /**
     * Compare chart, from CompareService::buildSeries().
     *
     * @param list<array{label: string, president: string, term_starts: list<int>, data: list<array{0: int, 1: float}>}> $series
     * @return array{type: string, series: list<array{key: string, label: string, term_starts: list<int>, points: list<array{0: int, 1: float}>}>}
     *   points = [month in office, change in trillions USD]; term_starts = months where a later term begins.
     */
    public static function compare(array $series): array
    {
        $out = [];
        foreach ($series as $i => $s) {
            $out[] = [
                'key' => 'term' . ($i + 1),
                'label' => $s['label'],
                'term_starts' => $s['term_starts'],
                'points' => $s['data'],
            ];
        }
        return ['type' => 'compare', 'series' => $out];
    }
}
