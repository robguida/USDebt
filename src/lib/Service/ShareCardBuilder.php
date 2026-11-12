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
    public const RENDER_VERSION = 3;

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
        $canTrend = count($entries) >= 2;
        $drawTrend = $showTrend && $canTrend;

        $pageQuery = $explicitDates
            ? 'start_date=' . $start->format('Y-m-d') . '&end_date=' . $end->format('Y-m-d')
            : '';
        if (!$showTrend) {
            $pageQuery .= ($pageQuery === '' ? '' : '&') . 'trend=0';
        }
        // The card does not depend on the exact dates picked, only on the days that have data.
        $cardQuery = 'start_date=' . $first->effectiveDate . '&end_date=' . $last->effectiveDate;
        if (!$drawTrend && $canTrend) {
            $cardQuery .= '&trend=0';
        }

        return new ShareCard(
            kind: ShareCard::KIND_RANGE,
            headline: ShareText::rangeHeadline($first, $last),
            detail: ShareText::rangeDetail($first, $last),
            // The home page shares as the site; a picked range shares as that range.
            description: $explicitDates ? ShareText::rangeDescription($first, $last) : ShareText::HOME_DESCRIPTION,
            pageQuery: $pageQuery,
            cardQuery: $cardQuery,
            cacheKey: self::cacheKey($dataset, $cardQuery),
            entries: $entries,
            drawTrend: $drawTrend,
            title: $explicitDates ? ShareText::rangeTitle($first, $last) : ShareText::HOME_TITLE,
            imageAlt: ShareText::rangeAlt($first, $last),
            shareText: ShareText::rangeShareText($first, $last),
            emailSubject: ShareText::rangeEmailSubject($first, $last),
            cardTrendMatters: $canTrend,
            downloadName: $first->effectiveDate === $last->effectiveDate
                ? 'us-debt-' . $first->effectiveDate . '.png'
                : 'us-debt-' . $first->effectiveDate . '-to-' . $last->effectiveDate . '.png',
        );
    }

    /**
     * @param list<int> $keys  Validated term ids (CompareService::validateSelection), at least 2.
     */
    public function forCompare(DebtDataset $dataset, array $keys, bool $showTrend): ?ShareCard
    {
        $series = $this->compareService->buildSeries($keys, $dataset);
        $years = $this->compareService->dataYears($keys, $dataset);
        if ($series === [] || $years === null) {
            return null;
        }
        $rows = [];
        foreach ($series as $s) {
            // The exact change (first to last data day of each term), the same
            // number the Compare page states; not the last monthly chart point.
            $rows[] = ['label' => $s['label'], 'change' => $s['change']];
        }
        $picks = 'compare=' . implode(',', $keys);
        $pageQuery = $picks . ($showTrend ? '' : '&trend=0');
        // The Compare card draws no trend line, so the trend switch does not change it.
        $cardQuery = $picks;

        return new ShareCard(
            kind: ShareCard::KIND_COMPARE,
            headline: ShareText::COMPARE_TITLE,
            detail: ShareText::compareDetail($years[0], $years[1]),
            description: ShareText::COMPARE_DESCRIPTION,
            pageQuery: $pageQuery,
            cardQuery: $cardQuery,
            cacheKey: self::cacheKey($dataset, $cardQuery),
            rows: $rows,
            title: ShareText::COMPARE_TITLE,
            note: ShareText::COMPARE_NOTE,
            imageAlt: ShareText::COMPARE_ALT,
            shareText: ShareText::COMPARE_SHARE_TEXT,
            emailSubject: ShareText::COMPARE_TITLE,
            downloadName: 'us-debt-presidential-terms.png',
        );
    }

    private static function cacheKey(DebtDataset $dataset, string $cardQuery): string
    {
        return hash('sha256', self::RENDER_VERSION . '|' . $dataset->savedOn() . '|' . $cardQuery);
    }
}
