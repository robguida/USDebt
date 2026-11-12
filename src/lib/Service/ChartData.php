<?php

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;
use USDebt\Model\DebtEntry;

/**
 * JSON-ready arrays for the date-range chart. Values are numbers, not strings.
 */
final class ChartData
{
    /**
     * @param list<DebtEntry> $entries  Oldest first.
     * @return array{labels: list<string>, values: list<float>}  Values in trillions USD.
     */
    public static function daily(array $entries): array
    {
        $labels = [];
        $values = [];
        foreach ($entries as $entry) {
            $labels[] = (new DateTimeImmutable($entry->effectiveDate))->format('n/j/y');
            $values[] = round($entry->totalDebt / 1_000_000_000_000, 6);
        }
        return ['labels' => $labels, 'values' => $values];
    }
}
