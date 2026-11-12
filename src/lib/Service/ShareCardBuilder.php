<?php

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;
use USDebt\Model\DebtDataset;
use USDebt\Model\ShareCard;

/**
 * Builds the ShareCard for a date range or a Compare view, from the one daily
 * Treasury copy (no extra Treasury calls). Returns null when the view has no
 * data to draw.
 */
final class ShareCardBuilder
{
    /** Bump when the card's look changes, so old cached cards are not served. */
    public const RENDER_VERSION = 1;

    public function __construct(private readonly CompareService $compareService)
    {
    }

    /**
     * @param bool $explicitDates  The visitor picked the dates (false = the home page's default range).
     */
    public function forRange(
        DebtDataset $dataset,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $showTrend,
        bool $explicitDates,
    ): ?ShareCard {
        $entries = $dataset->between($start, $end);
        if ($entries === []) {
            return null;
        }
        $first = $entries[0];
        $last = $entries[count($entries) - 1];
        $drawTrend = $showTrend && count($entries) >= 2;

        $pageQuery = $explicitDates
            ? 'start_date=' . $start->format('Y-m-d') . '&end_date=' . $end->format('Y-m-d')
            : '';
        if (!$showTrend) {
            $pageQuery .= ($pageQuery === '' ? '' : '&') . 'trend=0';
        }
        // The card does not depend on the exact dates picked, only on the days that have data.
        $cardQuery = 'start_date=' . $first->effectiveDate . '&end_date=' . $last->effectiveDate;
        if (!$drawTrend && count($entries) >= 2) {
            $cardQuery .= '&trend=0';
        }

        $headline = ShareText::rangeHeadline($first, $last);
        return new ShareCard(
            ShareCard::KIND_RANGE,
            $headline,
            ShareText::rangeDetail($first, $last),
            ShareText::rangeDescription($first, $last),
            $pageQuery,
            $cardQuery,
            self::cacheKey($dataset, $cardQuery),
            $entries,
            $drawTrend,
        );
    }

    /**
     * @param list<int> $keys  Validated term ids (CompareService::validateSelection), at least 2.
     */
    public function forCompare(DebtDataset $dataset, array $keys, bool $showTrend): ?ShareCard
    {
        $series = $this->compareService->buildSeries($keys, $dataset);
        if ($series === []) {
            return null;
        }
        $rows = [];
        foreach ($series as $s) {
            $lastPoint = $s['data'][count($s['data']) - 1];
            $rows[] = ['label' => $s['label'], 'change' => (float) $lastPoint[1] * 1_000_000_000_000];
        }
        $picks = 'compare=' . implode(',', $keys);
        $pageQuery = $picks . ($showTrend ? '' : '&trend=0');
        // The Compare card draws no trend line, so the trend switch does not change it.
        $cardQuery = $picks;

        return new ShareCard(
            ShareCard::KIND_COMPARE,
            ShareText::COMPARE_TITLE,
            ShareText::COMPARE_CAPTION,
            ShareText::compareDescription($rows),
            $pageQuery,
            $cardQuery,
            self::cacheKey($dataset, $cardQuery),
            [],
            false,
            $rows,
        );
    }

    private static function cacheKey(DebtDataset $dataset, string $cardQuery): string
    {
        return hash('sha256', self::RENDER_VERSION . '|' . $dataset->savedOn() . '|' . $cardQuery);
    }
}
