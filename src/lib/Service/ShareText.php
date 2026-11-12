<?php

declare(strict_types=1);

namespace USDebt\Service;

use USDebt\Model\DebtEntry;
use USDebt\Model\Per;
use USDebt\SiteConfig;

/**
 * Every word used by sharing: the picture card, the link preview tags (Open
 * Graph and Twitter), the share text sent to social sites and email, the
 * picture's alt text, the Share menu's labels and status lines (the page
 * script gets those from the menu's data attributes), and the picture card's
 * error lines. Kept in one place so the copy can be swapped without touching
 * the drawing or the page code. (A card request that fails unexpectedly gets
 * the site-wide FrontController::GENERIC_ERROR, which is not share wording.)
 *
 * Neutral voice: the words say what the numbers did (grew, fell, was about
 * the same), never why and never who.
 *
 * Plain text only. The page escapes it; the card draws it as-is.
 *
 * The site's name is not here: it lives in SiteConfig::NAME, the one place.
 *
 * "Your Share" (per person and per household, see Per) has its own section
 * at the end: the share and card words for those views, and the page words
 * that say what "per person" means. PLACEHOLDER COPY: the final words are
 * still being written; swap them there without touching any other file.
 */
final class ShareText
{
    public const SITE_DESCRIPTION = 'The U.S. national debt from U.S. Treasury data, as a chart, stats and a table.'
        . ' Compare how the debt changed during each presidential term.';

    /** Link preview for the home page (and for pages with nothing of their own to share). */
    public const HOME_TITLE = SiteConfig::NAME;
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

    // ---------- Share menu (View/share_menu.php) ----------

    public const MENU_BUTTON = 'Share';
    public const MENU_TITLE = 'Share this view';
    /** Phones only: the system share sheet. */
    public const MENU_NATIVE = 'Share with an app';
    /** Screen-reader label for the read-only link box. */
    public const MENU_LINK_LABEL = 'Link to this view';
    public const MENU_COPY = 'Copy link';
    public const MENU_DOWNLOAD = 'Download picture';
    /** Screen-reader note after a link that opens a new tab. */
    public const MENU_NEW_TAB = ' (opens in a new tab)';
    /** Status line after "Copy link" (the page script shows it; the menu hands it over in a data attribute). */
    public const MENU_COPIED = 'Link copied';
    public const MENU_COPY_FAILED = "Couldn't copy the link. Select it and copy it by hand.";

    /** @var array{x: string, facebook: string, linkedin: string, reddit: string, email: string} */
    public const MENU_NETWORKS = [
        'x' => 'Share to X',
        'facebook' => 'Share to Facebook',
        'linkedin' => 'Share to LinkedIn',
        'reddit' => 'Share to Reddit',
        'email' => 'Share by email',
    ];

    // ---------- Picture card errors (card.php, plain text) ----------

    /** 400: the view has no Treasury data to draw. */
    public const CARD_NO_DATA = 'There is no Treasury data for this view.';
    /** 503: this server cannot draw cards (no gd with FreeType, or a missing font). */
    public const CARD_UNAVAILABLE = 'The picture card is not available right now.';

    private const RANGE_DESCRIPTION_TAIL = ' Daily U.S. Treasury data.';

    /**
     * Card headline: "U.S. debt grew $9.32 trillion", "U.S. debt: $38.12 trillion" for one day.
     * Per person: "U.S. debt per person grew $62,104.33" (the entries are already per person).
     */
    public static function rangeHeadline(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        if (self::oneDay($first, $last)) {
            return self::subject($per) . ': ' . Html::compactMoney($last->totalDebt);
        }
        return self::subject($per) . ' ' . self::changed($first, $last);
    }

    /** Card line under the headline: "Jan 20, 2009 to Jan 19, 2017", or the one day. */
    public static function rangeDetail(DebtEntry $first, DebtEntry $last): string
    {
        return self::span($first, $last);
    }

    /** Link preview title: "U.S. debt, Jan 20, 2009 to Jan 19, 2017" ("U.S. debt per person, ..."). */
    public static function rangeTitle(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        return self::subject($per) . ', ' . self::span($first, $last);
    }

    /** Link preview description: "Debt grew $9.32 trillion over this period. Daily U.S. Treasury data." */
    public static function rangeDescription(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        $tail = $per->isTotal() ? self::RANGE_DESCRIPTION_TAIL : self::PER_DESCRIPTION_TAIL[$per->value];
        if (self::oneDay($first, $last)) {
            return self::debtWord($per) . ' was ' . Html::compactMoney($last->totalDebt) . ' on this day.' . $tail;
        }
        return self::debtWord($per) . ' ' . self::changed($first, $last) . ' over this period.' . $tail;
    }

    /** Alt text for the range card: "Chart of U.S. debt from Jan 20, 2009 to Jan 19, 2017. Debt grew $9.32 trillion." */
    public static function rangeAlt(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        if (self::oneDay($first, $last)) {
            return sprintf('Chart of %s on %s. %s was %s.', self::subject($per), self::span($first, $last), self::debtWord($per), Html::compactMoney($last->totalDebt));
        }
        return sprintf('Chart of %s from %s. %s %s.', self::subject($per), self::span($first, $last), self::debtWord($per), self::changed($first, $last));
    }

    /**
     * Share text, without the link (each site adds the link its own way):
     * "U.S. debt grew $9.32 trillion from Jan 20, 2009 to Jan 19, 2017."
     */
    public static function rangeShareText(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        if (self::oneDay($first, $last)) {
            return sprintf('%s on %s: %s.', self::subject($per), self::span($first, $last), Html::compactMoney($last->totalDebt));
        }
        return sprintf('%s %s from %s.', self::subject($per), self::changed($first, $last), self::span($first, $last));
    }

    /** Email subject: "U.S. debt, Jan 20, 2009 to Jan 19, 2017". */
    public static function rangeEmailSubject(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        return self::rangeTitle($first, $last, $per);
    }

    /** Compare card line under the headline: "Change in debt during each term, 1993 to 2026". */
    public static function compareDetail(int $firstYear, int $lastYear, Per $per = Per::Total): string
    {
        $what = 'Change in ' . lcfirst(self::debtWord($per));
        return $firstYear === $lastYear
            ? sprintf('%s during each term, %d', $what, $firstYear)
            : sprintf('%s during each term, %d to %d', $what, $firstYear, $lastYear);
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
    public static function emailBody(string $shareText, string $url, Per $per = Per::Total): string
    {
        return $shareText . ' ' . $url . "\n\n" . self::emailNote($per);
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

    // ---------- Your Share (per person, per household) ----------
    // PLACEHOLDER COPY until the final words are ready. Swap the words here;
    // the code around them does not change. The total view uses the words above.

    /** "U.S. debt", "U.S. debt per person", "U.S. debt per household". */
    private const SUBJECTS = [
        'total' => 'U.S. debt',
        'person' => 'U.S. debt per person',
        'household' => 'U.S. debt per household',
    ];

    /** Sentence start: "Debt grew ...", "Debt per person grew ...". */
    private const DEBT_WORDS = [
        'total' => 'Debt',
        'person' => 'Debt per person',
        'household' => 'Debt per household',
    ];

    private const PER_DESCRIPTION_TAIL = [
        'person' => ' Daily U.S. Treasury data and Census population estimates.',
        'household' => ' Daily U.S. Treasury data and Census household estimates.',
    ];

    /** Small note on the card: what "per person" means. */
    private const PER_NOTES = [
        'person' => "Each day's debt divided by the U.S. population that day (Census estimates).",
        'household' => "Each day's debt divided by the number of U.S. households that day (Census estimates).",
    ];

    /** Card footer source for per person and per household. */
    public const SOURCE_PER = 'Sources: U.S. Treasury (Debt to the Penny), U.S. Census Bureau';

    /** Last line of a shared email, per person and per household. */
    public const EMAIL_NOTE_PER = 'Data: U.S. Treasury, Debt to the Penny; U.S. Census Bureau estimates.';

    /** The switch on the page: "Show the debt as: Total | Per person | Per household". */
    public const PER_SWITCH_LABEL = 'Show the debt as';

    /** @var array{total: string, person: string, household: string} */
    public const PER_OPTIONS = [
        'total' => 'Total',
        'person' => 'Per person',
        'household' => 'Per household',
    ];

    /** People or households, for "÷ 341.8 million people". */
    private const DIVISOR_NOUNS = [
        'person' => 'people',
        'household' => 'households',
    ];

    /** "U.S. debt", "U.S. debt per person", "U.S. debt per household". */
    public static function subject(Per $per): string
    {
        return self::SUBJECTS[$per->value];
    }

    /** "Debt", "Debt per person", "Debt per household". */
    public static function debtWord(Per $per): string
    {
        return self::DEBT_WORDS[$per->value];
    }

    /** Card note for a range: what "per person" means ('' for the total). */
    public static function perNote(Per $per): string
    {
        return self::PER_NOTES[$per->value] ?? '';
    }

    /** Card footer source line. */
    public static function source(Per $per): string
    {
        return $per->isTotal() ? self::SOURCE : self::SOURCE_PER;
    }

    /** Last line of a shared email. */
    public static function emailNote(Per $per): string
    {
        return $per->isTotal() ? self::EMAIL_NOTE : self::EMAIL_NOTE_PER;
    }

    public static function compareTitle(Per $per): string
    {
        return $per->isTotal() ? self::COMPARE_TITLE : self::subject($per) . ' across presidential terms';
    }

    public static function compareDescription(Per $per): string
    {
        return $per->isTotal() ? self::COMPARE_DESCRIPTION
            : 'Change in ' . lcfirst(self::debtWord($per)) . ' during each term, side by side.'
                . ' Congress sets spending and taxes; terms are shown for reference only.';
    }

    public static function compareShareText(Per $per): string
    {
        return $per->isTotal() ? self::COMPARE_SHARE_TEXT : 'How the ' . self::subject($per) . ' changed across presidential terms.';
    }

    public static function compareAlt(Per $per): string
    {
        return $per->isTotal() ? self::COMPARE_ALT
            : 'Chart comparing the change in ' . self::subject($per) . ' during each presidential term.'
                . ' Terms are shown for reference only.';
    }

    /** Compare card note: the reference-only note, plus what "per person" means. */
    public static function compareNote(Per $per): string
    {
        return $per->isTotal() ? self::COMPARE_NOTE : self::COMPARE_NOTE . ' ' . self::perNote($per);
    }

    // ---------- Your Share: words on the page ----------

    /** Headline card label: "Debt today", "Debt today, per person". */
    public static function todayLabel(Per $per): string
    {
        return $per->isTotal() ? 'Debt today' : 'Debt today, ' . lcfirst(self::PER_OPTIONS[$per->value]);
    }

    /**
     * Headline card detail. Total: "$40,560,123,456.78 on Nov 9, 2026".
     * Per person: "$40.56 trillion ÷ 341.8 million people on Nov 9, 2026",
     * plus " (latest Census figure, 2025)" when the figure is held.
     */
    public static function todayDetail(Per $per, float $totalDebt, float $divisor, string $isoDate, ?int $heldYear): string
    {
        if ($per->isTotal()) {
            return Html::money($totalDebt) . ' on ' . Html::shortDate($isoDate);
        }
        return Html::compactMoney($totalDebt) . " \u{00F7} " . self::millions($divisor) . ' ' . self::DIVISOR_NOUNS[$per->value]
            . ' on ' . Html::shortDate($isoDate)
            . ($heldYear === null ? '' : sprintf(' (latest Census figure, %d)', $heldYear));
    }

    /** "Change in this range", "Change per person in this range". */
    public static function changeLabel(Per $per): string
    {
        return $per->isTotal() ? 'Change in this range' : 'Change ' . lcfirst(self::PER_OPTIONS[$per->value]) . ' in this range';
    }

    /** "Per day", "Per person, per day". */
    public static function perDayLabel(Per $per): string
    {
        return $per->isTotal() ? 'Per day' : self::PER_OPTIONS[$per->value] . ', per day';
    }

    /** Chart summary sentence start and data table column: "Total debt", "Debt per person". */
    public static function summarySubject(Per $per): string
    {
        return $per->isTotal() ? 'Total debt' : self::debtWord($per);
    }

    /** Compare chart, the y axis words. */
    public static function compareAxis(Per $per): string
    {
        return $per->isTotal()
            ? 'The y axis is the change, in trillions of U.S. dollars.'
            : 'The y axis is the change ' . lcfirst(self::PER_OPTIONS[$per->value]) . ', in U.S. dollars.';
    }

    /**
     * Stats tab note: how per person (or household) is worked out ('' for the total).
     *
     * @param array{year: int, date: string, value: float}|null $latest  PopulationService::latest()
     */
    public static function method(Per $per, ?array $latest): string
    {
        if ($per->isTotal()) {
            return '';
        }
        $text = $per === Per::Person
            ? "Per person: each day's debt divided by the U.S. population that day. Population is the Census estimate for July 1 of each year"
            : "Per household: each day's debt divided by the number of U.S. households that day. Households are the Census count for March of each year";
        $text .= ', with a straight line between years.';
        if ($latest !== null) {
            $text .= sprintf(' Days after %s use the %d figure.', Html::shortDate($latest['date']), $latest['year']);
        }
        return $text;
    }

    /** 341784857 -> "341.8 million". */
    private static function millions(float $value): string
    {
        return number_format($value / 1_000_000, 1) . ' million';
    }
}
