<?php

declare(strict_types=1);

namespace USDebt\Service;

use DateTimeImmutable;
use USDebt\Model\DebtDataset;
use USDebt\Model\Per;
use USDebt\Model\ShareCard;
use USDebt\SiteConfig;

/**
 * Builds the ShareCard for a date range or a Compare view, from the one daily
 * Treasury copy (no extra Treasury calls). Returns null when the view has no
 * data to draw.
 *
 * Per person and per household ("Your Share"): the card draws the scaled
 * days (each day's debt divided by that day's people or households), its
 * words say "per person", and every link carries per=person (or
 * per=household). The total view's links have no per at all.
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
        Per $per = Per::Total,
    ): ?ShareCard {
        $entries = $this->compareService->population()->scale($dataset->between($start, $end), $per);
        if ($entries === []) {
            return null;
        }
        $first = $entries[0];
        $last = $entries[count($entries) - 1];
        $canTrend = count($entries) >= 2;
        $drawTrend = $showTrend && $canTrend;

        $pageQuery = self::join(
            $explicitDates ? 'start_date=' . $start->format('Y-m-d') . '&end_date=' . $end->format('Y-m-d') : '',
            $per->query(),
            $showTrend ? '' : 'trend=0',
        );
        // The card does not depend on the exact dates picked, only on the days that have data.
        $cardQuery = self::join(
            'start_date=' . $first->effectiveDate . '&end_date=' . $last->effectiveDate,
            $per->query(),
            !$drawTrend && $canTrend ? 'trend=0' : '',
        );
        // The home page shares as the site; a picked range (or a per person
        // view, which the site-wide words do not describe) shares as that range.
        $asRange = $explicitDates || !$per->isTotal();
        $name = $per->isTotal() ? 'us-debt-' : 'us-debt-per-' . $per->value . '-';

        return new ShareCard(
            kind: ShareCard::KIND_RANGE,
            headline: ShareText::rangeHeadline($first, $last, $per),
            detail: ShareText::rangeDetail($first, $last),
            description: $asRange ? ShareText::rangeDescription($first, $last, $per) : ShareText::HOME_DESCRIPTION,
            pageQuery: $pageQuery,
            cardQuery: $cardQuery,
            cacheKey: self::cacheKey($dataset, $cardQuery),
            entries: $entries,
            drawTrend: $drawTrend,
            title: $asRange ? ShareText::rangeTitle($first, $last, $per) : ShareText::HOME_TITLE,
            note: ShareText::perNote($per),
            imageAlt: ShareText::rangeAlt($first, $last, $per),
            shareText: ShareText::rangeShareText($first, $last, $per),
            emailSubject: ShareText::rangeEmailSubject($first, $last, $per),
            cardTrendMatters: $canTrend,
            downloadName: $first->effectiveDate === $last->effectiveDate
                ? $name . $first->effectiveDate . '.png'
                : $name . $first->effectiveDate . '-to-' . $last->effectiveDate . '.png',
            per: $per,
        );
    }

    /**
     * @param list<int> $keys  Validated term ids (CompareService::validateSelection), at least 2.
     */
    public function forCompare(DebtDataset $dataset, array $keys, bool $showTrend, Per $per = Per::Total): ?ShareCard
    {
        $series = $this->compareService->buildSeries($keys, $dataset, $per);
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
        $pageQuery = self::join($picks, $per->query(), $showTrend ? '' : 'trend=0');
        // The Compare card draws no trend line, so the trend switch does not change it.
        $cardQuery = self::join($picks, $per->query());

        return new ShareCard(
            kind: ShareCard::KIND_COMPARE,
            headline: ShareText::compareTitle($per),
            detail: ShareText::compareDetail($years[0], $years[1], $per),
            description: ShareText::compareDescription($per),
            pageQuery: $pageQuery,
            cardQuery: $cardQuery,
            cacheKey: self::cacheKey($dataset, $cardQuery),
            rows: $rows,
            title: ShareText::compareTitle($per),
            note: ShareText::compareNote($per),
            imageAlt: ShareText::compareAlt($per),
            shareText: ShareText::compareShareText($per),
            emailSubject: ShareText::compareTitle($per),
            downloadName: $per->isTotal() ? 'us-debt-presidential-terms.png' : 'us-debt-per-' . $per->value . '-presidential-terms.png',
            per: $per,
        );
    }

    /** Query parts joined with "&", empty parts left out. */
    private static function join(string ...$parts): string
    {
        return implode('&', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /** The site name is drawn on the card, so a new name gets new cards. */
    private static function cacheKey(DebtDataset $dataset, string $cardQuery): string
    {
        return hash('sha256', self::RENDER_VERSION . '|' . SiteConfig::NAME . '|' . $dataset->savedOn() . '|' . $cardQuery);
    }
}
