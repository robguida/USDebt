<?php

declare(strict_types=1);

namespace USDebt\Service;

use USDebt\Model\DebtEntry;

/**
 * Every word used by sharing: the picture card and the link preview tags
 * (Open Graph and Twitter). Kept in one place so the copy can be swapped
 * without touching the drawing or the page code.
 *
 * Plain text only. The page escapes it; the card draws it as-is.
 */
final class ShareText
{
    public const SITE_NAME = 'Our U.S. Debt';

    public const SITE_DESCRIPTION = 'The U.S. national debt from U.S. Treasury data, as a chart, stats and a table.'
        . ' Compare how the debt changed during each presidential term.';

    public const SOURCE = 'Source: U.S. Treasury, Debt to the Penny';

    public const COMPARE_TITLE = 'Debt change by presidential term';

    public const COMPARE_CAPTION = 'Congress sets spending and taxes. Presidential terms are shown for reference only.';

    /** Date-range headline: "U.S. debt grew $14.90 trillion from Jan 2, 2020 to Nov 9, 2026". */
    public static function rangeHeadline(DebtEntry $first, DebtEntry $last): string
    {
        if ($first->effectiveDate === $last->effectiveDate) {
            return sprintf('U.S. debt was %s on %s', Html::compactMoney($last->totalDebt), Html::shortDate($last->effectiveDate));
        }
        $change = $last->totalDebt - $first->totalDebt;
        $span = sprintf('from %s to %s', Html::shortDate($first->effectiveDate), Html::shortDate($last->effectiveDate));
        if ($change > 0) {
            return sprintf('U.S. debt grew %s %s', Html::compactMoney($change), $span);
        }
        if ($change < 0) {
            return sprintf('U.S. debt fell %s %s', Html::compactMoney(-$change), $span);
        }
        return 'U.S. debt did not change ' . $span;
    }

    /** Line under the headline: "From $23.22 trillion to $38.12 trillion." */
    public static function rangeDetail(DebtEntry $first, DebtEntry $last): string
    {
        if ($first->effectiveDate === $last->effectiveDate) {
            return Html::money($last->totalDebt) . '.';
        }
        return sprintf('From %s to %s.', Html::compactMoney($first->totalDebt), Html::compactMoney($last->totalDebt));
    }

    /** Link preview description for a date range. */
    public static function rangeDescription(DebtEntry $first, DebtEntry $last): string
    {
        return self::rangeDetail($first, $last) . ' ' . self::SOURCE . '.';
    }

    /** One Compare row's amount: "+$1.42 trillion". */
    public static function compareChange(float $change): string
    {
        return Html::compactMoney($change, true);
    }

    /**
     * Link preview description for Compare: "Bill Clinton (1993-2001): +$1.42 trillion. ..."
     *
     * @param list<array{label: string, change: float}> $rows
     */
    public static function compareDescription(array $rows): string
    {
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row['label'] . ': ' . self::compareChange($row['change']) . '.';
        }
        return implode(' ', $parts) . ' ' . self::COMPARE_CAPTION;
    }

    /** Last Compare row on the card when not every term fits. */
    public static function moreRows(int $count): string
    {
        return sprintf('and %d more on the site', $count);
    }

    /** Alt text for the card image. */
    public static function imageAlt(string $headline): string
    {
        return $headline . '. ' . self::SOURCE . '.';
    }

    /** The site address in the card footer: "usdebt.bengusa.pro". */
    public static function siteAddress(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $path = parse_url($baseUrl, PHP_URL_PATH);
        return (is_string($host) ? $host : '') . (is_string($path) ? rtrim($path, '/') : '');
    }
}
