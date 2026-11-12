<?php

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;
use USDebt\Model\DebtDataset;

/**
 * Builds the president Compare chart data.
 *
 * - There is NO limit on how many presidents can be compared. Input is only
 *   validated: unknown term ids are ignored and duplicates are removed.
 * - Each series is the change in total debt since the president took office,
 *   one point per month in office (x = month number, y = change in trillions USD).
 * - When a president with a gap between terms (e.g. Donald Trump 2017-2021 and
 *   2025-2029) has both terms selected, they become ONE continuous series: the
 *   second term's months continue on the x-axis right after the first term's, and
 *   its debt change is added on top of the change at the end of the first term.
 *   The years in between (another president's term) are left out.
 */
final class CompareService
{
    private const TRILLION = 1_000_000_000_000;

    /** @var array<int, array{pres: string, start: string, end: string, img: string}> */
    private array $terms;

    /**
     * @param array<int, array{pres: string, start: string, end: string, img: string}>|null $terms
     */
    public function __construct(?array $terms = null)
    {
        $this->terms = $terms ?? PresidentService::getPresidentConfig();
    }

    /**
     * Keep only real term ids, once each, in term order (oldest first).
     *
     * @return list<int>
     */
    public function validateSelection(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $wanted = [];
        foreach ($raw as $value) {
            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            $text = (string) $value;
            if (!ctype_digit($text) || (string) (int) $text !== $text) {
                continue;
            }
            $wanted[(int) $text] = true;
        }
        $keys = [];
        foreach (array_keys($this->terms) as $key) {
            if (isset($wanted[$key])) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * @param list<int> $keys  Validated term ids (see validateSelection).
     * @return list<array{label: string, president: string, term_starts: list<int>, data: list<array{0: int, 1: float}>}>
     *   term_starts = x values where a later term begins (empty for one term).
     *   The Compare chart draws a diamond at each one (js/usdebt.js).
     */
    public function buildSeries(array $keys, DebtDataset $dataset): array
    {
        $groups = [];
        foreach ($keys as $key) {
            if (!isset($this->terms[$key])) {
                continue;
            }
            $groups[$this->terms[$key]['pres']][] = $key;
        }

        $series = [];
        foreach ($groups as $name => $termKeys) {
            usort($termKeys, fn (int $a, int $b): int => $this->start($a) <=> $this->start($b));

            $points = [];
            $termStarts = [];
            $years = [];
            $x = 0;
            $carried = 0.0; // debt change (dollars) at the end of the previous term(s)
            foreach ($termKeys as $key) {
                $entries = $dataset->between($this->start($key), $this->end($key));
                if ($entries === []) {
                    continue; // no Treasury data for this term (yet)
                }
                if ($points !== []) {
                    $termStarts[] = $x + 1;
                }
                $baseline = $entries[0]->totalDebt;
                $month = null;
                foreach ($entries as $entry) {
                    $entryMonth = substr($entry->effectiveDate, 0, 7);
                    if ($entryMonth === $month) {
                        continue;
                    }
                    $month = $entryMonth;
                    $x++;
                    $points[] = [$x, round(($carried + $entry->totalDebt - $baseline) / self::TRILLION, 3)];
                }
                $carried += end($entries)->totalDebt - $baseline;
                $years[] = $this->start($key)->format('Y') . '-' . $this->end($key)->format('Y');
            }
            if ($points === []) {
                continue;
            }
            $series[] = [
                'label' => $name . ' (' . implode(', ', $years) . ')',
                'president' => $name,
                'term_starts' => $termStarts,
                'data' => $points,
            ];
        }
        return $series;
    }

    private function start(int $key): DateTimeImmutable
    {
        return new DateTimeImmutable($this->terms[$key]['start']);
    }

    private function end(int $key): DateTimeImmutable
    {
        return new DateTimeImmutable($this->terms[$key]['end']);
    }
}
