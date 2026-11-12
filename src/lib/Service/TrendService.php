<?php

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;
use DateTimeZone;
use USDebt\Model\DebtEntry;

/**
 * Straight trend lines (ordinary least squares) for the charts.
 *
 * - The x value is the real calendar day, not the position in the list, so
 *   weekends and holidays (days with no Treasury data) keep their true width.
 * - Input may be in any order (newest first or oldest first).
 * - Fewer than 2 points, or all points on the same x, gives null: there is no line.
 * - The projection carries the same straight line past the last reported day:
 *   25% of the shown range, at least PROJECTION_MIN_DAYS, at most
 *   PROJECTION_MAX_DAYS (12 months). One point per calendar day.
 * - Chart values are in trillions of U.S. dollars, like ChartData, by
 *   default. Pass $unit = 1.0 for plain dollars (the per person and per
 *   household views, whose values are a few hundred thousand dollars at most).
 *   The line is fitted on the values as given, so for those views it is
 *   fitted on the per person (or per household) series the chart draws.
 *
 * growth() adds a second, curved line next to the straight one: steady
 * percent growth, value = A * e^(k * t), with t in calendar days. It uses the
 * same x values and the same projection rules as the straight line.
 */
final class TrendService
{
    public const PROJECTION_SHARE = 0.25;
    public const PROJECTION_MIN_DAYS = 3;
    public const PROJECTION_MAX_DAYS = 365;
    private const TRILLION = 1_000_000_000_000;
    private const DAY_SECONDS = 86_400;

    /**
     * Least-squares straight line y = intercept + slope * x.
     *
     * @param list<array{0: int|float, 1: int|float}> $points  [x, y] pairs, any order.
     * @return array{slope: float, intercept: float, r2: float}|null
     *   r2 is 1.0 for a perfectly flat line (every point is on it).
     */
    public static function fit(array $points): ?array
    {
        $n = count($points);
        if ($n < 2) {
            return null;
        }
        $sumX = 0.0;
        $sumY = 0.0;
        foreach ($points as [$x, $y]) {
            $sumX += $x;
            $sumY += $y;
        }
        $meanX = $sumX / $n;
        $meanY = $sumY / $n;

        // Centred sums keep the maths accurate with large x (day numbers) and y.
        $sxx = 0.0;
        $sxy = 0.0;
        $syy = 0.0;
        foreach ($points as [$x, $y]) {
            $dx = $x - $meanX;
            $dy = $y - $meanY;
            $sxx += $dx * $dx;
            $sxy += $dx * $dy;
            $syy += $dy * $dy;
        }
        if ($sxx == 0.0) {
            return null; // every point on the same x: no line
        }
        $slope = $sxy / $sxx;
        $intercept = $meanY - $slope * $meanX;

        $ssRes = 0.0;
        foreach ($points as [$x, $y]) {
            $diff = $y - ($intercept + $slope * $x);
            $ssRes += $diff * $diff;
        }
        $r2 = $syy == 0.0 ? 1.0 : max(0.0, 1.0 - $ssRes / $syy);

        return ['slope' => $slope, 'intercept' => $intercept, 'r2' => $r2];
    }

    /**
     * Trend for the date-range chart.
     *
     * @param list<DebtEntry> $entries  Any order.
     * @return array{slope_per_day: float, intercept: float, r2: float, first_date: string, last_date: string,
     *               projection_days: int, fitted: list<array{0: string, 1: float}>, projection: list<array{0: string, 1: float}>}|null
     *   slope_per_day and intercept are in trillions of dollars; intercept is the
     *   line's value on first_date. fitted has one point per entry, oldest first,
     *   on the same dates as the chart. projection starts the day after last_date.
     */
    public static function daily(array $entries, float $unit = self::TRILLION): ?array
    {
        $fit = self::fitDays($entries, $unit);
        if ($fit === null) {
            return null;
        }
        ['entries' => $entries, 'points' => $points, 'line' => $line] = $fit;

        $fitted = [];
        foreach ($entries as $i => $entry) {
            $fitted[] = [$entry->effectiveDate, round($line['intercept'] + $line['slope'] * $points[$i][0], 6)];
        }

        $lastOffset = $points[count($points) - 1][0];
        $projection = self::project(
            $entries[count($entries) - 1]->effectiveDate,
            $lastOffset,
            static fn (int $t): float => $line['intercept'] + $line['slope'] * $t
        );

        return [
            'slope_per_day' => $line['slope'],
            'intercept' => $line['intercept'],
            'r2' => $line['r2'],
            'first_date' => $entries[0]->effectiveDate,
            'last_date' => $entries[count($entries) - 1]->effectiveDate,
            'projection_days' => count($projection),
            'fitted' => $fitted,
            'projection' => $projection,
        ];
    }

    /**
     * Growth curve (steady percent growth) for the date-range chart.
     *
     * Fitted by least squares on ln(value) against calendar days since the
     * first day, which gives value = start_value * e^(rate_per_day * t).
     * The log of a value needs the value to be above zero: a day with a zero,
     * negative or non-finite value is left out of the fit and counted in
     * skipped_points (the other days still count). Fewer than 2 usable days,
     * or all usable days on one date, gives null. So does a curve whose
     * growth % or projection would not be a finite number (no curve is drawn).
     *
     * @param list<DebtEntry> $entries  Any order.
     * @return array{rate_per_day: float, start_value: float, annual_growth_pct: float, r2: float,
     *               skipped_points: int, first_date: string, last_date: string, projection_days: int,
     *               fitted: list<array{0: string, 1: float}>, projection: list<array{0: string, 1: float}>}|null
     *   start_value is in trillions of dollars, the curve's value on first_date.
     *   annual_growth_pct = (e^(365 * rate_per_day) - 1) * 100.
     *   r2 is measured on the original dollar scale (curve vs. the real
     *   values), not on the log scale the fit uses, so it can be compared with
     *   the straight line's r2. It is floored at 0.
     *   fitted has one point per entry, oldest first, on the same dates as the
     *   chart (a skipped day still gets the curve's value). projection uses
     *   the same days as the straight line's projection.
     */
    public static function growth(array $entries, float $unit = self::TRILLION): ?array
    {
        $curve = self::fitGrowthDays($entries, $unit);
        if ($curve === null) {
            return null;
        }
        ['entries' => $entries, 'offsets' => $offsets, 'a' => $a, 'k' => $k, 'r2' => $r2, 'skipped' => $skipped] = $curve;

        $fitted = [];
        foreach ($entries as $i => $entry) {
            $fitted[] = [$entry->effectiveDate, round($a * exp($k * $offsets[$i]), 6)];
        }

        $projection = self::project(
            $entries[count($entries) - 1]->effectiveDate,
            $offsets[count($offsets) - 1],
            static fn (int $t): float => $a * exp($k * $t)
        );

        return [
            'rate_per_day' => $k,
            'start_value' => $a,
            'annual_growth_pct' => self::annualGrowthPct($k),
            'r2' => $r2,
            'skipped_points' => $skipped,
            'first_date' => $entries[0]->effectiveDate,
            'last_date' => $entries[count($entries) - 1]->effectiveDate,
            'projection_days' => count($projection),
            'fitted' => $fitted,
            'projection' => $projection,
        ];
    }

    /**
     * Growth curve's yearly percent only; null when there is no curve. Same
     * curve as growth(), without the fitted points or the projection (for the
     * Stats tab).
     *
     * @param list<DebtEntry> $entries  Any order.
     */
    public static function growthPctPerYear(array $entries, float $unit = self::TRILLION): ?float
    {
        $curve = self::fitGrowthDays($entries, $unit);
        return $curve === null ? null : self::annualGrowthPct($curve['k']);
    }

    /**
     * Slope of the date-range trend line only, in $unit (default trillions of
     * dollars) per calendar day; null when there is no line. Same line as daily(), without
     * building the fitted points or the projection (for the Stats tab).
     *
     * @param list<DebtEntry> $entries  Any order.
     */
    public static function slopePerDay(array $entries, float $unit = self::TRILLION): ?float
    {
        $fit = self::fitDays($entries, $unit);
        return $fit === null ? null : $fit['line']['slope'];
    }

    /**
     * Sort by date and fit the line over calendar days since the first day.
     *
     * @param list<DebtEntry> $entries  Any order.
     * @return array{entries: list<DebtEntry>, points: list<array{0: int, 1: float}>,
     *               line: array{slope: float, intercept: float, r2: float}}|null
     */
    private static function fitDays(array $entries, float $unit): ?array
    {
        usort($entries, static fn (DebtEntry $a, DebtEntry $b): int => strcmp($a->effectiveDate, $b->effectiveDate));
        if (count($entries) < 2) {
            return null;
        }
        $firstDay = self::dayNumber($entries[0]->effectiveDate);
        $points = [];
        foreach ($entries as $entry) {
            $points[] = [self::dayNumber($entry->effectiveDate) - $firstDay, $entry->totalDebt / $unit];
        }
        $line = self::fit($points);
        if ($line === null) {
            return null;
        }
        return ['entries' => $entries, 'points' => $points, 'line' => $line];
    }

    /**
     * Sort by date and fit the growth curve over calendar days since the first
     * day: least squares on ln(value), then r2 on the original scale.
     *
     * @param list<DebtEntry> $entries  Any order.
     * @return array{entries: list<DebtEntry>, offsets: list<int>, a: float, k: float, r2: float, skipped: int}|null
     */
    private static function fitGrowthDays(array $entries, float $unit): ?array
    {
        usort($entries, static fn (DebtEntry $a, DebtEntry $b): int => strcmp($a->effectiveDate, $b->effectiveDate));
        if (count($entries) < 2) {
            return null;
        }
        $firstDay = self::dayNumber($entries[0]->effectiveDate);
        $offsets = [];
        $logPoints = [];
        $usable = [];
        $skipped = 0;
        foreach ($entries as $entry) {
            $t = self::dayNumber($entry->effectiveDate) - $firstDay;
            $offsets[] = $t;
            $value = $entry->totalDebt / $unit;
            if (!is_finite($value) || $value <= 0.0) {
                $skipped++; // no log of zero or less: leave this day out, keep the rest
                continue;
            }
            $logPoints[] = [$t, log($value)];
            $usable[] = [$t, $value];
        }
        $logLine = self::fit($logPoints);
        if ($logLine === null) {
            return null; // fewer than 2 usable days, or all on one date
        }
        $a = exp($logLine['intercept']);
        $k = $logLine['slope'];

        // A tiny or odd value on a short range can make the curve explode
        // (e.g. 1e-300 one day and 1e13 the next): its yearly % or its
        // projection would be infinite, and an infinite number cannot be sent
        // to the page. Then there is simply no curve; it is never an error.
        // The curve is largest at one of its two ends, so checking the start
        // and the last projection day covers every point in between.
        $lastOffset = $offsets[count($offsets) - 1];
        $furthest = $lastOffset + self::projectionDays($lastOffset);
        if (!is_finite($a) || !is_finite($k) || !is_finite(self::annualGrowthPct($k))
            || !is_finite($a * exp($k * $furthest)) || $a <= 0.0) {
            return null;
        }

        // r2 on the original scale: how closely the curve follows the real values.
        $mean = array_sum(array_column($usable, 1)) / count($usable);
        $ssTot = 0.0;
        $ssRes = 0.0;
        foreach ($usable as [$t, $value]) {
            $ssTot += ($value - $mean) ** 2;
            $ssRes += ($value - $a * exp($k * $t)) ** 2;
        }
        $r2 = $ssTot == 0.0 ? 1.0 : max(0.0, 1.0 - $ssRes / $ssTot);
        if (!is_finite($r2)) {
            return null;
        }

        return ['entries' => $entries, 'offsets' => $offsets, 'a' => $a, 'k' => $k, 'r2' => $r2, 'skipped' => $skipped];
    }

    /** Yearly percent change for a daily rate k: (e^(365k) - 1) * 100. */
    private static function annualGrowthPct(float $k): float
    {
        return (exp(365 * $k) - 1) * 100;
    }

    /**
     * Projection points past the last reported day, one per calendar day, for
     * any line or curve. The number of days follows projectionDays().
     *
     * @param callable(int): float $valueAt  Value in trillions at a day offset from the first day.
     * @return list<array{0: string, 1: float}>
     */
    private static function project(string $lastDate, int $lastOffset, callable $valueAt): array
    {
        $days = self::projectionDays($lastOffset);
        $last = new DateTimeImmutable($lastDate, new DateTimeZone('UTC'));
        $projection = [];
        for ($d = 1; $d <= $days; $d++) {
            $projection[] = [$last->modify("+{$d} days")->format('Y-m-d'), round($valueAt($lastOffset + $d), 6)];
        }
        return $projection;
    }

    /**
     * Trend for one Compare series (x = month in office). No projection.
     *
     * @param list<array{0: int, 1: float}> $points  [month, change in trillions], any order.
     * @return array{slope_per_month: float, intercept: float, r2: float, fitted: list<array{0: int, 1: float}>}|null
     *   fitted has one point per input point, sorted by month (smallest first),
     *   whatever order the input was in.
     */
    public static function series(array $points): ?array
    {
        usort($points, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $line = self::fit($points);
        if ($line === null) {
            return null;
        }
        $fitted = [];
        foreach ($points as [$x]) {
            $fitted[] = [$x, round($line['intercept'] + $line['slope'] * $x, 3)];
        }
        return [
            'slope_per_month' => $line['slope'],
            'intercept' => $line['intercept'],
            'r2' => $line['r2'],
            'fitted' => $fitted,
        ];
    }

    /** Days to project past the last reported day, for a range of $rangeDays calendar days. */
    public static function projectionDays(int $rangeDays): int
    {
        $days = (int) ceil($rangeDays * self::PROJECTION_SHARE);
        return min(self::PROJECTION_MAX_DAYS, max(self::PROJECTION_MIN_DAYS, $days));
    }

    /** Whole days since 1970-01-01 for a Y-m-d date (UTC, so no daylight-saving gaps). */
    private static function dayNumber(string $isoDate): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new \InvalidArgumentException("Not a Y-m-d date: {$isoDate}");
        }
        return (int) floor($date->getTimestamp() / self::DAY_SECONDS);
    }
}
