<?php

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;
use USDebt\Model\DebtDataset;
use USDebt\Model\Per;

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
 * - Each series also carries its exact total change ('change', in dollars):
 *   for every picked term, its last data day minus its first data day, added
 *   up over the president's picked terms (the same sum the joined line ends
 *   on). The chart points are one per month (the first data day of each
 *   month), so the last point can be up to a month short of the term's end.
 *   Stated totals (the Compare page summary and the share card) use 'change',
 *   never the last chart point.
 * - Per person and per household ("Your Share", Per): every day's debt is
 *   first divided by that day's people or households (PopulationService),
 *   then the same steps run on those values. So a term's change is
 *   D_last/P_last - D_first/P_first per person, in dollars, and the chart
 *   points are in dollars (not trillions). Two terms of one president add up
 *   the same way.
 */
final class CompareService
{
    private const TRILLION = 1_000_000_000_000;

    /** @var array<int, array{pres: string, start: string, end: string, img: string}> */
    private array $terms;

    private readonly PopulationService $population;

    /**
     * @param array<int, array{pres: string, start: string, end: string, img: string}>|null $terms
     * @param PopulationService|null $population  For per person and per household (default: the shipped Census data).
     */
    public function __construct(?array $terms = null, ?PopulationService $population = null)
    {
        $this->terms = $terms ?? PresidentService::getPresidentConfig();
        $this->population = $population ?? new PopulationService();
    }

    public function population(): PopulationService
    {
        return $this->population;
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
     * @return list<array{label: string, president: string, term_starts: list<int>, data: list<array{0: int, 1: float}>, change: float, months: int}>
     *   term_starts = x values where a later term begins (empty for one term).
     *   The Compare chart draws a diamond at each one (js/usdebt.js).
     *   change = exact debt change in dollars, first to last data day of each
     *   picked term, summed. months = months in office with data (the last x).
     *   Per person or per household: change is in dollars per person (or
     *   household), and the points are in dollars, not trillions.
     */
    public function buildSeries(array $keys, DebtDataset $dataset, Per $per = Per::Total): array
    {
        $unit = $per->isTotal() ? self::TRILLION : 1;
        $decimals = $per->isTotal() ? 3 : 2;
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
                $entries = $this->population->scale($dataset->between($this->start($key), $this->end($key)), $per);
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
                    $points[] = [$x, round(($carried + $entry->totalDebt - $baseline) / $unit, $decimals)];
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
                'change' => $carried,
                'months' => $x,
            ];
        }
        return $series;
    }

    /**
     * First and last year that has data in the picked terms, for the share
     * card ("1993 to 2026"). A term still running ends at the latest data.
     *
     * @param list<int> $keys  Validated term ids.
     * @return array{0: int, 1: int}|null  Null when no picked term has data.
     */
    public function dataYears(array $keys, DebtDataset $dataset): ?array
    {
        $first = null;
        $last = null;
        foreach ($keys as $key) {
            if (!isset($this->terms[$key])) {
                continue;
            }
            $entries = $dataset->between($this->start($key), $this->end($key));
            if ($entries === []) {
                continue;
            }
            $from = (int) substr($entries[0]->effectiveDate, 0, 4);
            $to = (int) substr($entries[count($entries) - 1]->effectiveDate, 0, 4);
            $first = $first === null ? $from : min($first, $from);
            $last = $last === null ? $to : max($last, $to);
        }
        return $first === null || $last === null ? null : [$first, $last];
    }

    /**
     * The first day with data in the picked terms (Y-m-d), or null when none has any.
     *
     * @param list<int> $keys  Validated term ids.
     */
    public function firstDataDate(array $keys, DebtDataset $dataset): ?string
    {
        $first = null;
        foreach ($keys as $key) {
            if (!isset($this->terms[$key])) {
                continue;
            }
            $entries = $dataset->between($this->start($key), $this->end($key));
            if ($entries !== [] && ($first === null || $entries[0]->effectiveDate < $first)) {
                $first = $entries[0]->effectiveDate;
            }
        }
        return $first;
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
