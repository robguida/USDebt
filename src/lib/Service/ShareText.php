<?php

declare(strict_types=1);

namespace USDebt\Service;

use USDebt\Model\DebtEntry;

/**
 * Every word used by sharing: the picture card, the link preview tags (Open
 * Graph and Twitter), the share text sent to social sites and email, and the
 * picture's alt text. Kept in one place so the copy can be swapped without
 * touching the drawing or the page code.
 *
 * Neutral voice: the words say what the numbers did (grew, fell, was about
 * the same), never why and never who.
 *
 * Plain text only. The page escapes it; the card draws it as-is.
 */
final class ShareText
{
    public const SITE_NAME = 'Our U.S. Debt';

    public const SITE_DESCRIPTION = 'The U.S. national debt from U.S. Treasury data, as a chart, stats and a table.'
        . ' Compare how the debt changed during each presidential term.';

    /** Link preview for the home page (and for pages with nothing of their own to share). */
    public const HOME_TITLE = 'U.S. Debt Tracker';
    public const HOME_DESCRIPTION = 'See how U.S. debt has changed over any date range, using daily U.S. Treasury data.';
    /** Alt text for the default card on a page with nothing of its own to share (an error page). */
    public const HOME_ALT = 'Chart of U.S. debt over the last month, from daily U.S. Treasury data.';

    /** Card footer, left side. The site address is drawn on the right. */
    public const SOURCE = 'Source: U.S. Treasury, Debt to the Penny';

    /** Last line of a shared email. */
    public const EMAIL_NOTE = 'Data: U.S. Treasury, Debt to the Penny.';

    public const COMPARE_TITLE = 'U.S. debt across presidential terms';
    public const COMPARE_DESCRIPTION = 'Change in debt during each term, side by side.'
        . ' Congress sets spending and taxes; terms are shown for reference only.';
    /** Small note on the Compare card. */
    public const COMPARE_NOTE = 'Congress sets spending and taxes. Terms are shown for reference only.';
    public const COMPARE_SHARE_TEXT = 'How the U.S. debt changed across presidential terms.';
    public const COMPARE_ALT = 'Chart comparing the change in U.S. debt during each presidential term.'
        . ' Terms are shown for reference only.';

    private const RANGE_DESCRIPTION_TAIL = ' Daily U.S. Treasury data.';

    /** Card headline: "U.S. debt grew $9.32 trillion", "U.S. debt: $38.12 trillion" for one day. */
    public static function rangeHeadline(DebtEntry $first, DebtEntry $last): string
    {
        if (self::oneDay($first, $last)) {
            return 'U.S. debt: ' . Html::compactMoney($last->totalDebt);
        }
        return 'U.S. debt ' . self::changed($first, $last);
    }

    /** Card line under the headline: "Jan 20, 2009 to Jan 19, 2017", or the one day. */
    public static function rangeDetail(DebtEntry $first, DebtEntry $last): string
    {
        return self::span($first, $last);
    }

    /** Link preview title: "U.S. debt, Jan 20, 2009 to Jan 19, 2017". */
    public static function rangeTitle(DebtEntry $first, DebtEntry $last): string
    {
        return 'U.S. debt, ' . self::span($first, $last);
    }

    /** Link preview description: "Debt grew $9.32 trillion over this period. Daily U.S. Treasury data." */
    public static function rangeDescription(DebtEntry $first, DebtEntry $last): string
    {
        if (self::oneDay($first, $last)) {
            return 'Debt was ' . Html::compactMoney($last->totalDebt) . ' on this day.' . self::RANGE_DESCRIPTION_TAIL;
        }
        return 'Debt ' . self::changed($first, $last) . ' over this period.' . self::RANGE_DESCRIPTION_TAIL;
    }

    /** Alt text for the range card: "Chart of U.S. debt from Jan 20, 2009 to Jan 19, 2017. Debt grew $9.32 trillion." */
    public static function rangeAlt(DebtEntry $first, DebtEntry $last): string
    {
        if (self::oneDay($first, $last)) {
            return sprintf('Chart of U.S. debt on %s. Debt was %s.', self::span($first, $last), Html::compactMoney($last->totalDebt));
        }
        return sprintf('Chart of U.S. debt from %s. Debt %s.', self::span($first, $last), self::changed($first, $last));
    }

    /**
     * Share text, without the link (each site adds the link its own way):
     * "U.S. debt grew $9.32 trillion from Jan 20, 2009 to Jan 19, 2017."
     */
    public static function rangeShareText(DebtEntry $first, DebtEntry $last): string
    {
        if (self::oneDay($first, $last)) {
            return sprintf('U.S. debt on %s: %s.', self::span($first, $last), Html::compactMoney($last->totalDebt));
        }
        return sprintf('U.S. debt %s from %s.', self::changed($first, $last), self::span($first, $last));
    }

    /** Email subject: "U.S. debt, Jan 20, 2009 to Jan 19, 2017". */
    public static function rangeEmailSubject(DebtEntry $first, DebtEntry $last): string
    {
        return self::rangeTitle($first, $last);
    }

    /** Compare card line under the headline: "Change in debt during each term, 1993 to 2026". */
    public static function compareDetail(int $firstYear, int $lastYear): string
    {
        return $firstYear === $lastYear
            ? sprintf('Change in debt during each term, %d', $firstYear)
            : sprintf('Change in debt during each term, %d to %d', $firstYear, $lastYear);
    }

    /** One Compare row's amount: "+$1.42 trillion". */
    public static function compareChange(float $change): string
    {
        return Html::compactMoney($change, true);
    }

    /** Last Compare row on the card when not every term fits. */
    public static function moreRows(int $count): string
    {
        return sprintf('and %d more on the site', $count);
    }

    /** Email body: the share text, the link, then the data source. */
    public static function emailBody(string $shareText, string $url): string
    {
        return $shareText . ' ' . $url . "\n\n" . self::EMAIL_NOTE;
    }

    /** The site address in the card footer: "usdebt.bengusa.pro". */
    public static function siteAddress(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $path = parse_url($baseUrl, PHP_URL_PATH);
        return (is_string($host) ? $host : '') . (is_string($path) ? rtrim($path, '/') : '');
    }

    /**
     * "grew $9.32 trillion", "fell $9.32 trillion", or "was about the same"
     * when the change rounds to $0.00.
     */
    private static function changed(DebtEntry $first, DebtEntry $last): string
    {
        $change = round($last->totalDebt - $first->totalDebt, 2);
        if ($change > 0) {
            return 'grew ' . Html::compactMoney($change);
        }
        if ($change < 0) {
            return 'fell ' . Html::compactMoney(-$change);
        }
        return 'was about the same';
    }

    /** "Jan 20, 2009 to Jan 19, 2017", or "Jan 20, 2009" for one day. */
    private static function span(DebtEntry $first, DebtEntry $last): string
    {
        if (self::oneDay($first, $last)) {
            return Html::shortDate($last->effectiveDate);
        }
        return Html::shortDate($first->effectiveDate) . ' to ' . Html::shortDate($last->effectiveDate);
    }

    private static function oneDay(DebtEntry $first, DebtEntry $last): bool
    {
        return $first->effectiveDate === $last->effectiveDate;
    }
}
