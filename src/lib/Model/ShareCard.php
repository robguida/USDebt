<?php

declare(strict_types=1);

namespace USDebt\Model;

/**
 * Everything needed to share one view: the words for the link preview, the
 * page and card links (as query strings), the card's cache key, and the data
 * the picture card draws. Built by ShareCardBuilder.
 *
 * - pageQuery: the page's own link ('' = the home page). Keeps the dates as
 *   the visitor picked them, and trend=0 when the trend is off.
 * - cardQuery: the card's link, normalized: dates moved to the first and last
 *   day that has data, trend=0 only when the card would draw a trend line,
 *   Compare picks in term order with no duplicates. Two views that draw the
 *   same card get the same cardQuery.
 * - cacheKey: cardQuery plus the day the data was saved, so a card is redrawn
 *   when new Treasury data arrives.
 * - title, description: the link preview words (og:title, og:description).
 * - headline, detail, note: the words drawn on the card.
 * - imageAlt: the card's alt text (og:image:alt and the Share menu preview).
 * - shareText, emailSubject: what the Share menu sends, without the link.
 * - cardTrendMatters: the card changes with the trend switch (a range card
 *   with a trend line to draw), so the page script updates its link.
 * - downloadName: file name for "Download picture".
 */
final class ShareCard
{
    public const KIND_RANGE = 'range';
    public const KIND_COMPARE = 'compare';

    /**
     * @param list<DebtEntry>                            $entries  Range card: the days drawn, oldest first. Empty for Compare.
     * @param list<array{label: string, change: float}>  $rows     Compare card: one row per line, change in dollars. Empty for a range.
     * @param string                                     $title    Link preview title.
     * @param string                                     $note     Small note under the card's detail line ('' = none).
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $headline,
        public readonly string $detail,
        public readonly string $description,
        public readonly string $pageQuery,
        public readonly string $cardQuery,
        public readonly string $cacheKey,
        public readonly array $entries = [],
        public readonly bool $drawTrend = false,
        public readonly array $rows = [],
        public readonly string $title = '',
        public readonly string $note = '',
        public readonly string $imageAlt = '',
        public readonly string $shareText = '',
        public readonly string $emailSubject = '',
        public readonly bool $cardTrendMatters = false,
        public readonly string $downloadName = 'usdebt.png',
    ) {
    }
}
