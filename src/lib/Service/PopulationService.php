<?php

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use USDebt\Model\DebtEntry;
use USDebt\Model\Per;

/**
 * U.S. population and number of households for any day, for "Your Share"
 * (the debt per person and per household).
 *
 * Data: Data/us_population_households.json, one Census figure per year
 * (sources and notes are inside the file). Public domain.
 *
 * - Each yearly figure is pinned to one day of its year (its "anchor"):
 *   population on July 1 (Census estimates are for July 1); households on
 *   March 15 (the Census CPS survey behind them is taken in March; the middle
 *   of the month is used).
 * - Between two anchors the value is a straight line (linear interpolation
 *   by calendar day). On an anchor day it is that year's figure exactly.
 * - After the last anchor the last figure is held, not projected: no guess
 *   about years Census has not published. Such a day is "held" (latest
 *   Census figure). Before the first anchor the first figure is held the
 *   same way (earliest Census figure; Treasury data starts 1993-01-04,
 *   before the first population anchor, 1993-07-01).
 * - The population figures mix Census vintages, so there is a step between
 *   2019 and 2020 (about 3.2 million instead of the usual 1.5 to 2.5 million).
 *   It is kept as published.
 *
 * A bad year entry (a year that is not a year, or a value that is not a
 * number above zero) is skipped and logged once, and the straight line spans
 * the gap. If fewer than 2 good years remain for people or households, the
 * per person and per household views are not available (available()): the
 * page shows the total instead, with a logged warning.
 *
 * The file is read the first time it is needed, so the total view never
 * reads it.
 */
final class PopulationService
{
    public const POPULATION_ANCHOR = '07-01';
    public const HOUSEHOLDS_ANCHOR = '03-15';

    public const HELD_LATEST = 'latest';
    public const HELD_EARLIEST = 'earliest';

    private const DAY_SECONDS = 86_400;

    /** @var array{population: list<array{0: int, 1: float, 2: int}>, households: list<array{0: int, 1: float, 2: int}>}|null [day number, value, year] */
    private ?array $series = null;

    private ?bool $available = null;

    /** @var array<string, true> Problems already logged by this object when there is no LogOnce. */
    private array $warned = [];

    /**
     * @param string|null  $file  The JSON file (default: Data/us_population_households.json).
     * @param LogOnce|null $log   Logs a lasting data problem once per window across PHP processes. Without it, once per object.
     */
    public function __construct(private readonly ?string $file = null, private readonly ?LogOnce $log = null)
    {
    }

    /**
     * A service over fixed yearly figures (tests, or another data file already read).
     *
     * @param array<int|string, int|float> $population  year => people
     * @param array<int|string, int|float> $households  year => households
     */
    public static function fromYears(array $population, array $households, ?LogOnce $log = null): self
    {
        $service = new self(null, $log);
        $service->series = [
            'population' => $service->anchors($population, self::POPULATION_ANCHOR, 'population'),
            'households' => $service->anchors($households, self::HOUSEHOLDS_ANCHOR, 'households'),
        ];
        return $service;
    }

    /**
     * Whether the per person and per household views can be worked out: the
     * data loads and has at least 2 good years of people and of households.
     * When not, a warning is logged (once) and the caller shows the total.
     * Call this before using any other method with a per mode.
     */
    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        try {
            $series = $this->load();
        } catch (RuntimeException $e) {
            $this->warn('unavailable', 'UsDebt: ' . $e->getMessage() . '. Per person and per household views fall back to the total.');
            return $this->available = false;
        }
        foreach ($series as $name => $points) {
            if (count($points) < 2) {
                $this->warn('few-' . $name, "UsDebt: Population data: fewer than 2 good years of {$name}. Per person and per household views fall back to the total.");
                return $this->available = false;
            }
        }
        return $this->available = true;
    }

    /**
     * The first Census figure for this mode: its year, anchor day (Y-m-d) and value.
     * Days before that day use this figure (held).
     *
     * @return array{year: int, date: string, value: float}|null  Null for the total view.
     */
    public function earliest(Per $per): ?array
    {
        if ($per->isTotal()) {
            return null;
        }
        [$day, $value, $year] = $this->load()[self::measure($per)][0];
        return ['year' => $year, 'date' => gmdate('Y-m-d', $day * self::DAY_SECONDS), 'value' => $value];
    }

    public static function defaultFile(): string
    {
        return dirname(__DIR__) . '/Data/us_population_households.json';
    }

    /** People in the U.S. on this day (Y-m-d). */
    public function population(string $isoDate): float
    {
        return self::valueAt($this->load()['population'], self::dayNumber($isoDate));
    }

    /** U.S. households on this day (Y-m-d). */
    public function households(string $isoDate): float
    {
        return self::valueAt($this->load()['households'], self::dayNumber($isoDate));
    }

    /** What the debt is divided by in this mode on this day: people, households, or 1 for the total. */
    public function divisor(Per $per, string $isoDate): float
    {
        return match ($per) {
            Per::Total => 1.0,
            Per::Person => $this->population($isoDate),
            Per::Household => $this->households($isoDate),
        };
    }

    /**
     * Whether this day uses a held figure: "latest" (after the last anchor,
     * the latest Census figure), "earliest" (before the first anchor), or
     * null (a real or interpolated figure, or the total view).
     */
    public function held(Per $per, string $isoDate): ?string
    {
        if ($per->isTotal()) {
            return null;
        }
        $points = $this->load()[self::measure($per)];
        $day = self::dayNumber($isoDate);
        if ($day > $points[count($points) - 1][0]) {
            return self::HELD_LATEST;
        }
        if ($day < $points[0][0]) {
            return self::HELD_EARLIEST;
        }
        return null;
    }

    /**
     * The last Census figure for this mode: its year, anchor day (Y-m-d) and value.
     *
     * @return array{year: int, date: string, value: float}|null  Null for the total view.
     */
    public function latest(Per $per): ?array
    {
        if ($per->isTotal()) {
            return null;
        }
        $points = $this->load()[self::measure($per)];
        [$day, $value, $year] = $points[count($points) - 1];
        return ['year' => $year, 'date' => gmdate('Y-m-d', $day * self::DAY_SECONDS), 'value' => $value];
    }

    /**
     * The days with the debt divided by the population (or households) of
     * that same day. A scaled entry's totalDebt is the debt per person (or
     * per household) on that day, in dollars. The total view gets the
     * entries back unchanged.
     *
     * @param list<DebtEntry> $entries
     * @return list<DebtEntry>
     */
    public function scale(array $entries, Per $per): array
    {
        if ($per->isTotal()) {
            return $entries;
        }
        $points = $this->load()[self::measure($per)];
        $out = [];
        foreach ($entries as $entry) {
            $out[] = new DebtEntry($entry->effectiveDate, $entry->totalDebt / self::valueAt($points, self::dayNumber($entry->effectiveDate)));
        }
        return $out;
    }

    /** @return 'population'|'households' */
    private static function measure(Per $per): string
    {
        return $per === Per::Household ? 'households' : 'population';
    }

    /**
     * @param list<array{0: int, 1: float, 2: int}> $points  Sorted by day.
     */
    private static function valueAt(array $points, int $day): float
    {
        $last = count($points) - 1;
        if ($day <= $points[0][0]) {
            return $points[0][1];
        }
        if ($day >= $points[$last][0]) {
            return $points[$last][1];
        }
        // Binary search: the last anchor on or before $day.
        $lo = 0;
        $hi = $last;
        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            if ($points[$mid][0] <= $day) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        [$d0, $v0] = $points[$lo];
        [$d1, $v1] = $points[$hi];
        return $v0 + ($v1 - $v0) * ($day - $d0) / ($d1 - $d0);
    }

    /**
     * @return array{population: list<array{0: int, 1: float, 2: int}>, households: list<array{0: int, 1: float, 2: int}>}
     */
    private function load(): array
    {
        if ($this->series !== null) {
            return $this->series;
        }
        $file = $this->file ?? self::defaultFile();
        $raw = is_file($file) ? file_get_contents($file) : false;
        if ($raw === false) {
            throw new RuntimeException("Population data missing: {$file}");
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Population data is not valid JSON: {$file}", 0, $e);
        }
        if (!is_array($data) || !is_array($data['population'] ?? null) || !is_array($data['households'] ?? null)) {
            throw new RuntimeException("Population data has no population or households: {$file}");
        }
        return $this->series = [
            'population' => $this->anchors($data['population'], self::POPULATION_ANCHOR, 'population'),
            'households' => $this->anchors($data['households'], self::HOUSEHOLDS_ANCHOR, 'households'),
        ];
    }

    /**
     * Year => value to sorted [day number of the anchor, value, year].
     * A bad entry is skipped (not fatal; loop rule) and the skipped years are
     * logged once. The list can end up short or empty: available() says
     * whether it is usable.
     *
     * @param array<mixed> $years
     * @return list<array{0: int, 1: float, 2: int}>
     */
    private function anchors(array $years, string $monthDay, string $name): array
    {
        $points = [];
        $skipped = [];
        foreach ($years as $year => $value) {
            $text = (string) $year;
            if (!ctype_digit($text) || (int) $text < 1900 || (int) $text > 2200
                || (!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value <= 0) {
                $skipped[] = $text;
                continue;
            }
            $points[] = [self::dayNumber($text . '-' . $monthDay), (float) $value, (int) $text];
        }
        if ($skipped !== []) {
            $this->warn('skipped-' . $name, sprintf(
                'UsDebt: Population data: skipped %d bad %s %s (%s); the straight line spans the gap.',
                count($skipped),
                $name,
                count($skipped) === 1 ? 'entry' : 'entries',
                implode(', ', array_slice($skipped, 0, 10))
            ));
        }
        usort($points, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        return $points;
    }

    /** Log a lasting data problem once: across PHP processes with a LogOnce, else once per object. */
    private function warn(string $scope, string $message): void
    {
        if ($this->log !== null) {
            $this->log->log('population:' . $scope, $message);
            return;
        }
        if (!isset($this->warned[$scope])) {
            $this->warned[$scope] = true;
            error_log($message);
        }
    }

    /** Whole days since 1970-01-01 for a Y-m-d date (UTC). */
    private static function dayNumber(string $isoDate): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new \InvalidArgumentException("Not a Y-m-d date: {$isoDate}");
        }
        return intdiv($date->getTimestamp(), self::DAY_SECONDS);
    }
}
