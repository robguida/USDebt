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
 * - Chart values are in trillions of U.S. dollars, like ChartData.
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
    public static function daily(array $entries): ?array
    {
        $fit = self::fitDays($entries);
        if ($fit === null) {
            return null;
        }
        ['entries' => $entries, 'points' => $points, 'line' => $line] = $fit;

        $fitted = [];
        foreach ($entries as $i => $entry) {
            $fitted[] = [$entry->effectiveDate, round($line['intercept'] + $line['slope'] * $points[$i][0], 6)];
        }

        $lastOffset = $points[count($points) - 1][0];
        $days = self::projectionDays($lastOffset);
        $lastDate = new DateTimeImmutable($entries[count($entries) - 1]->effectiveDate, new DateTimeZone('UTC'));
        $projection = [];
        for ($k = 1; $k <= $days; $k++) {
            $projection[] = [
                $lastDate->modify("+{$k} days")->format('Y-m-d'),
                round($line['intercept'] + $line['slope'] * ($lastOffset + $k), 6),
            ];
        }

        return [
            'slope_per_day' => $line['slope'],
            'intercept' => $line['intercept'],
            'r2' => $line['r2'],
            'first_date' => $entries[0]->effectiveDate,
            'last_date' => $entries[count($entries) - 1]->effectiveDate,
            'projection_days' => $days,
            'fitted' => $fitted,
            'projection' => $projection,
        ];
    }

    /**
     * Slope of the date-range trend line only, in trillions of dollars per
     * calendar day; null when there is no line. Same line as daily(), without
     * building the fitted points or the projection (for the Stats tab).
     *
     * @param list<DebtEntry> $entries  Any order.
     */
    public static function slopePerDay(array $entries): ?float
    {
        $fit = self::fitDays($entries);
        return $fit === null ? null : $fit['line']['slope'];
    }

    /**
     * Sort by date and fit the line over calendar days since the first day.
     *
     * @param list<DebtEntry> $entries  Any order.
     * @return array{entries: list<DebtEntry>, points: list<array{0: int, 1: float}>,
     *               line: array{slope: float, intercept: float, r2: float}}|null
     */
    private static function fitDays(array $entries): ?array
    {
        usort($entries, static fn (DebtEntry $a, DebtEntry $b): int => strcmp($a->effectiveDate, $b->effectiveDate));
        if (count($entries) < 2) {
            return null;
        }
        $firstDay = self::dayNumber($entries[0]->effectiveDate);
        $points = [];
        foreach ($entries as $entry) {
            $points[] = [self::dayNumber($entry->effectiveDate) - $firstDay, $entry->totalDebt / self::TRILLION];
        }
        $line = self::fit($points);
        if ($line === null) {
            return null;
        }
        return ['entries' => $entries, 'points' => $points, 'line' => $line];
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
