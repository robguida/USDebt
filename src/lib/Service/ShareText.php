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
 * on the page (the "Show numbers as" switch, headline cards, Stats rows,
 * chart axis titles and the Census footnote). Swap them there without
 * touching any other file.
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

    /** Saved tab (opt-in cookie), next to the Chart / Stats / Table switch. */
    public const TAB_MEMORY_LABEL = 'Remember my tab';
    public const TAB_MEMORY_NOTE = 'Saves one cookie on this device. It holds only which tab you picked, nothing else. Untick to remove it.';
    public const TAB_MEMORY_SAVED = "Saved. We'll keep this tab.";
    public const TAB_MEMORY_REMOVED = 'Cookie removed.';
    public const TAB_MEMORY_FAILED = "Couldn't save. Cookies seem to be blocked in this browser.";
    /** The home link for the maker's site, read by screen readers. */
    public const LOGO_LINK_LABEL = 'bengUSA.pro home';

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
     * Per person: "U.S. debt grew $62,104.33 per person", "U.S. debt per person: $118,670.55"
     * for one day (the entries are already per person).
     */
    public static function rangeHeadline(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        if (self::oneDay($first, $last)) {
            return self::subject($per) . ': ' . Html::compactMoney($last->totalDebt);
        }
        return self::SUBJECTS['total'] . ' ' . self::changed($first, $last, $per);
    }

    /** Card line under the headline: "Jan 20, 2009 to Jan 19, 2017", or the one day. */
    public static function rangeDetail(DebtEntry $first, DebtEntry $last): string
    {
        return self::span($first, $last);
    }

    /**
     * Link preview title: "U.S. debt, Jan 20, 2009 to Jan 19, 2017" ("U.S. debt per person, ...").
     * One day, per person: "U.S. debt per person on Jan 20, 2009".
     */
    public static function rangeTitle(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        if (!$per->isTotal() && self::oneDay($first, $last)) {
            return self::subject($per) . ' on ' . self::span($first, $last);
        }
        return self::subject($per) . ', ' . self::span($first, $last);
    }

    /** Link preview description: "Debt grew $9.32 trillion over this period. Daily U.S. Treasury data." */
    public static function rangeDescription(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        $tail = $per->isTotal() ? self::RANGE_DESCRIPTION_TAIL : self::PER_DESCRIPTION_TAIL[$per->value];
        if (self::oneDay($first, $last)) {
            return 'Debt was ' . Html::compactMoney($last->totalDebt) . self::perWords($per) . ' on this day.' . $tail;
        }
        return 'Debt ' . self::changed($first, $last, $per) . ' over this period.' . $tail;
    }

    /** Alt text for the range card: "Chart of U.S. debt from Jan 20, 2009 to Jan 19, 2017. Debt grew $9.32 trillion." */
    public static function rangeAlt(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        if (self::oneDay($first, $last)) {
            return sprintf('Chart of %s on %s. Debt was %s%s.', self::subject($per), self::span($first, $last), Html::compactMoney($last->totalDebt), self::perWords($per));
        }
        return sprintf('Chart of %s from %s. Debt %s.', self::subject($per), self::span($first, $last), self::changed($first, $last, $per));
    }

    /**
     * Share text, without the link (each site adds the link its own way):
     * "U.S. debt grew $9.32 trillion from Jan 20, 2009 to Jan 19, 2017."
     * Per person: "U.S. debt grew $26,000.00 per person from Jan 20, 2009 to Jan 19, 2017."
     */
    public static function rangeShareText(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        if (self::oneDay($first, $last)) {
            return sprintf('%s on %s: %s%s.', self::SUBJECTS['total'], self::span($first, $last), Html::compactMoney($last->totalDebt), self::perWords($per));
        }
        return sprintf('%s %s from %s.', self::SUBJECTS['total'], self::changed($first, $last, $per), self::span($first, $last));
    }

    /** Email subject: "U.S. debt, Jan 20, 2009 to Jan 19, 2017" ("U.S. debt per person, Jan 20, 2009" for one day). */
    public static function rangeEmailSubject(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        return self::subject($per) . ', ' . self::span($first, $last);
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

    /** The site address in the card footer: "bengusa.pro/ourusdebt". */
    public static function siteAddress(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $path = parse_url($baseUrl, PHP_URL_PATH);
        return (is_string($host) ? $host : '') . (is_string($path) ? rtrim($path, '/') : '');
    }

    /**
     * "grew $9.32 trillion", "fell $9.32 trillion", or "was about the same"
     * when the change rounds to $0.00. Per person: "grew $26,000.00 per person",
     * "was about the same per person".
     */
    private static function changed(DebtEntry $first, DebtEntry $last, Per $per = Per::Total): string
    {
        $change = round($last->totalDebt - $first->totalDebt, 2);
        if ($change > 0) {
            return 'grew ' . Html::compactMoney($change) . self::perWords($per);
        }
        if ($change < 0) {
            return 'fell ' . Html::compactMoney(-$change) . self::perWords($per);
        }
        return 'was about the same' . self::perWords($per);
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
    // Final copy (2026-11-12). Swap the words here; the code around them does
    // not change. The total view uses the words above and is unchanged.
    // Neutral: "per person" is arithmetic (the debt divided by residents),
    // never "owe", "your debt" or "burden".

    /** "U.S. debt", "U.S. debt per person", "U.S. debt per household". */
    private const SUBJECTS = [
        'total' => 'U.S. debt',
        'person' => 'U.S. debt per person',
        'household' => 'U.S. debt per household',
    ];

    /** "Debt", "Debt per person", "Debt per household". */
    private const DEBT_WORDS = [
        'total' => 'Debt',
        'person' => 'Debt per person',
        'household' => 'Debt per household',
    ];

    /** After an amount: "grew $26,000.00 per person". Nothing for the total. */
    private const PER_WORDS = [
        'total' => '',
        'person' => ' per person',
        'household' => ' per household',
    ];

    private const PER_DESCRIPTION_TAIL = [
        'person' => ' Daily U.S. Treasury data and Census Bureau counts.',
        'household' => ' Daily U.S. Treasury data and Census Bureau counts.',
    ];

    /** Small mode tag on the card: what "per person" means. */
    private const PER_NOTES = [
        'person' => 'Per person: debt divided by U.S. population',
        'household' => 'Per household: debt divided by U.S. households',
    ];

    /** Card footer source for per person and per household (the site address is drawn on the right). */
    public const SOURCE_PER = 'Source: U.S. Treasury, Census Bureau';

    /** Last line of a shared email, per person and per household. */
    public const EMAIL_NOTE_PER = 'Data: U.S. Treasury, Debt to the Penny; U.S. Census Bureau.';

    /** The switch on the page: "Show numbers as: Total | Per person | Per household". */
    public const PER_SWITCH_LABEL = 'Show numbers as';

    /** Screen-reader name of the switch. */
    public const PER_SWITCH_ARIA = 'Choose how to show the numbers';

    /** @var array{total: string, person: string, household: string} */
    public const PER_OPTIONS = [
        'total' => 'Total',
        'person' => 'Per person',
        'household' => 'Per household',
    ];

    /** One line under the switch: what the picked mode means. */
    private const PER_HELP = [
        'total' => 'The whole national debt.',
        'person' => 'The debt divided by the U.S. population: every resident, all ages.',
        'household' => 'The debt divided by the number of U.S. households.',
    ];

    /** Headline cards and Stats rows, per mode. The total keeps its words from before. */
    private const PAGE_LABELS = [
        'total' => [
            'today' => 'Debt today',
            'change' => 'Change in this range',
            'per_day' => 'Per day',
            'stats_start' => 'Starting debt amount',
            'stats_end' => 'Last debt amount of date range',
            'stats_change' => 'Change during the date range',
            'stats_per_day' => 'Average change per day',
            'stats_above' => 'Days the debt was above the start*',
            'stats_below' => 'Days the debt was below the start*',
            'axis' => 'Debt (dollars)',
            'compare_axis' => 'Change since taking office',
        ],
        'person' => [
            'today' => 'Debt per person today',
            'change' => 'Change per person in this range',
            'per_day' => 'Per person per day',
            'stats_start' => 'Per person at start',
            'stats_end' => 'Per person at end',
            'stats_change' => 'Change per person',
            'stats_per_day' => 'Average per person per day',
            'stats_above' => 'Days the debt per person was above the start*',
            'stats_below' => 'Days the debt per person was below the start*',
            'axis' => 'Debt per person (dollars)',
            'compare_axis' => 'Change per person since taking office',
        ],
        'household' => [
            'today' => 'Debt per household today',
            'change' => 'Change per household in this range',
            'per_day' => 'Per household per day',
            'stats_start' => 'Per household at start',
            'stats_end' => 'Per household at end',
            'stats_change' => 'Change per household',
            'stats_per_day' => 'Average per household per day',
            'stats_above' => 'Days the debt per household was above the start*',
            'stats_below' => 'Days the debt per household was below the start*',
            'axis' => 'Debt per household (dollars)',
            'compare_axis' => 'Change per household since taking office',
        ],
    ];

    /** Source line under the chart, per person and per household. */
    public const DEBT_SOURCE_LINE = 'Debt: U.S. Treasury, Debt to the Penny.';

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
        return $per->isTotal() ? self::COMPARE_NOTE : self::COMPARE_NOTE . ' ' . self::perNote($per) . '.';
    }

    /** After an amount: " per person", " per household", or '' for the total. */
    public static function perWords(Per $per): string
    {
        return self::PER_WORDS[$per->value];
    }

    // ---------- Your Share: words on the page ----------

    /** One line under the switch: what the picked mode means. */
    public static function perHelp(Per $per): string
    {
        return self::PER_HELP[$per->value];
    }

    /**
     * A headline card or Stats row label for this mode.
     *
     * @param 'today'|'change'|'per_day'|'stats_start'|'stats_end'|'stats_change'|'stats_per_day'|'stats_above'|'stats_below'|'axis'|'compare_axis' $key
     */
    public static function label(Per $per, string $key): string
    {
        return self::PAGE_LABELS[$per->value][$key];
    }

    /** Date-range chart, y axis title: "Debt (dollars)", "Debt per person (dollars)". */
    public static function axisTitle(Per $per): string
    {
        return self::label($per, 'axis');
    }

    /** Compare chart, y axis title: "Change since taking office", "Change per person since taking office". */
    public static function compareAxisTitle(Per $per): string
    {
        return self::label($per, 'compare_axis');
    }

    /** Heading of the results card: "The debt over time", "Debt per person over time". */
    public static function resultsHeading(Per $per): string
    {
        return $per->isTotal() ? 'The debt over time' : self::debtWord($per) . ' over time';
    }

    /** Screen-reader name of the date-range chart. */
    public static function chartLabel(Per $per): string
    {
        return $per->isTotal()
            ? 'Line chart of the total U.S. debt for the chosen dates'
            : 'Line chart of ' . lcfirst(self::debtWord($per)) . ' over time, for the chosen dates';
    }

    /**
     * Footnote under the chart, per person and per household ('' for the total):
     * where the counts come from and how days between yearly counts are filled.
     */
    public static function censusFootnote(Per $per, ?int $latestYear, ?int $earliestYear = null): string
    {
        if ($per->isTotal()) {
            return '';
        }
        return 'Per-person and per-household figures divide the debt by U.S. Census Bureau population and household counts.'
            . ' Counts are yearly; days between years are estimated in straight-line steps, and the latest Census figure'
            . ($latestYear === null ? '' : sprintf(' (%d)', $latestYear))
            . ' is used for recent dates.'
            . self::earlyNote($earliestYear);
    }

    /**
     * Said when the view uses days before the first Census figure: "The 1993
     * Census figure is used for dates before it." ('' when it does not).
     */
    private static function earlyNote(?int $earliestYear): string
    {
        return $earliestYear === null ? '' : sprintf(' The %d Census figure is used for dates before it.', $earliestYear);
    }

    /** Headline card label: "Debt today", "Debt per person today". */
    public static function todayLabel(Per $per): string
    {
        return self::label($per, 'today');
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
        return self::label($per, 'change');
    }

    /** "Per day", "Per person per day". */
    public static function perDayLabel(Per $per): string
    {
        return self::label($per, 'per_day');
    }

    /** Chart summary sentence start and data table column: "Total debt", "Debt per person". */
    public static function summarySubject(Per $per): string
    {
        return $per->isTotal() ? 'Total debt' : self::debtWord($per);
    }

    /**
     * The straight trend, in words, for the chart summary and the Stats tab:
     * "about +$14.2 billion per day", "about +$41.20 per person per day".
     *
     * @param string $money  The amount, already formatted and signed.
     * @param string $unit   "day" or "month".
     */
    public static function trendAmount(Per $per, string $money, string $unit): string
    {
        return 'about ' . $money . self::perWords($per) . ' per ' . $unit;
    }

    /** Compare summary line: "+$1.42 trillion after 48 months in office", "+$4,100.00 per person after ...". */
    public static function compareLine(Per $per, string $label, string $money, int $months): string
    {
        return sprintf(
            '%s: %s%s after %d %s in office.',
            $label,
            $money,
            self::perWords($per),
            $months,
            $months === 1 ? 'month' : 'months'
        );
    }

    /**
     * The debt line's name on the chart. The legend shows the label with its
     * unit; the tooltip shows the name (the unit is in the tooltip's amount).
     *
     * @return array{label: string, name: string}
     */
    public static function debtSeries(Per $per): array
    {
        return match ($per) {
            Per::Total => ['label' => 'Total debt (trillions USD)', 'name' => 'Total debt'],
            Per::Person => ['label' => 'Debt per person (USD)', 'name' => 'Debt per person'],
            Per::Household => ['label' => 'Debt per household (USD)', 'name' => 'Debt per household'],
        };
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
    public static function method(Per $per, ?array $latest, ?int $earliestYear = null): string
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
        return $text . self::earlyNote($earliestYear);
    }

    /** 341784857 -> "341.8 million". */
    private static function millions(float $value): string
    {
        return number_format($value / 1_000_000, 1) . ' million';
    }
}
