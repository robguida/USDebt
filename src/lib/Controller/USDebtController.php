<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 6:05 PM
 */

declare(strict_types=1);

namespace USDebt\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use USDebt\Exception\UserFacingException;
use USDebt\Model\DebtDataset;
use USDebt\Model\Per;
use USDebt\Model\RequestModel;
use USDebt\Model\ShareCard;
use USDebt\Service\ChartData;
use USDebt\Service\CompareService;
use USDebt\Service\DebtSource;
use USDebt\Service\Html;
use USDebt\Service\PopulationService;
use USDebt\Service\PresidentService;
use USDebt\Service\ShareCardBuilder;
use USDebt\Service\ShareLinks;
use USDebt\Service\ShareText;
use USDebt\Service\StatsCalculator;
use USDebt\SiteConfig;

class USDebtController
{

    private readonly ShareCardBuilder $shareCards;

    /**
     * @param ShareLinks $shareLinks  Absolute links for the link preview tags. Defaults to the production address.
     */
    public function __construct(
        private readonly DebtSource $debtSource,
        private readonly CompareService $compareService,
        private readonly ShareLinks $shareLinks = new ShareLinks(),
    ) {
        $this->shareCards = new ShareCardBuilder($compareService);
    }

    public function compareService(): CompareService
    {
        return $this->compareService;
    }

    /**
     * Fill in the default range (the last month, ending today) and check the
     * order. Shared by the page and the share card.
     *
     * @throws UserFacingException  The start date is after the end date.
     */
    public static function resolveRange(RequestModel $requestModel): void
    {
        if ($requestModel->getEndDate() === null) {
            $requestModel->setEndDate(self::today());
        }
        if ($requestModel->getStartDate() === null) {
            $requestModel->setStartDate(self::monthsBefore($requestModel->getEndDate(), 1));
        }
        if ($requestModel->getStartDate() > $requestModel->getEndDate()) {
            throw new UserFacingException('The start date must be on or before the end date.');
        }
    }

    /**
     * Date-range view: headline numbers, then chart, stats and table. Defaults to the last month.
     */
    public function defaultView(RequestModel $requestModel): string
    {
        // The home page (no dates picked) shares as the home page, not as fixed dates.
        $explicitDates = $requestModel->getStartDate() !== null || $requestModel->getEndDate() !== null;
        self::resolveRange($requestModel);

        $per = $requestModel->per();
        $dataset = $this->debtSource->load();
        // Per person or per household: each day's debt divided by that day's people or households.
        $entries = $this->population()->scale(
            $dataset->between($requestModel->getStartDate(), $requestModel->getEndDate()),
            $per,
        );
        $cards = $this->debtTodayCard($dataset, $per);
        $hasTrend = false;

        if ($entries === []) {
            $results = $this->message(sprintf(
                'There is no Treasury data for this date range. Data runs from %s to %s.',
                $dataset->firstDate() ?? '?',
                $dataset->lastDate() ?? '?'
            ));
        } else {
            $stats = StatsCalculator::summarize($entries, $per->unitDollars());
            $first = $entries[0];
            $last = $entries[count($entries) - 1];
            if ($stats !== null) {
                // Per person: D_end/P_end - D_start/P_start, the change in the line the chart draws.
                $cards[] = [
                    'label' => ShareText::changeLabel($per),
                    'value' => Html::compactMoney($stats['delta'], true),
                    'detail' => Html::shortDate($first->effectiveDate) . ' to ' . Html::shortDate($last->effectiveDate),
                ];
                $cards[] = [
                    'label' => ShareText::perDayLabel($per),
                    'value' => Html::compactMoney($stats['average_per_day'], true),
                    'detail' => 'Average over ' . number_format($stats['total_days']) . ' calendar days',
                ];
            }
            $chart = ChartData::daily($entries, $per);
            if (!$per->isTotal()) {
                $chart['divisor'] = $this->divisorInfo($per);
            }
            $hasTrend = in_array('trend', array_column($chart['series'], 'key'), true);
            $results = $this->loadFile('tabs.php', [
                'cookie_path' => $this->shareLinks->cookiePath(),
                'graph' => $this->loadFile('chart_daily.php', [
                    'chart' => $chart,
                    'first' => $first,
                    'last' => $last,
                    'show_trend' => $requestModel->showTrend(),
                    'per' => $per,
                    'footnote' => $this->censusFootnote($per, $first->effectiveDate),
                ]),
                'stats' => $this->loadFile('tab_stats.php', [
                    'stats' => $stats,
                    'per' => $per,
                    'method_note' => ShareText::method($per, $this->population()->latest($per), $this->earlyHeldYear($per, $first->effectiveDate)),
                ]),
                'data' => $this->loadFile('tab_data.php', [
                    'datas' => array_reverse($entries),
                    'header' => ShareText::summarySubject($per),
                    'download_href' => $this->shareLinks->localDownload(implode('&', array_filter([
                        'start_date=' . $requestModel->getStartDate()->format('Y-m-d'),
                        'end_date=' . $requestModel->getEndDate()->format('Y-m-d'),
                        $per->query(),
                    ]))),
                ]),
            ]);
        }

        $share = $this->shareCards->forRange(
            $dataset,
            $requestModel->getStartDate(),
            $requestModel->getEndDate(),
            $requestModel->showTrend(),
            $explicitDates,
            $per,
        );
        $viewQuery = $explicitDates
            ? 'start_date=' . $requestModel->getStartDate()->format('Y-m-d') . '&end_date=' . $requestModel->getEndDate()->format('Y-m-d')
            : '';
        return $this->page($requestModel, $results, $dataset, $cards, '', $hasTrend, $share, $viewQuery);
    }

    /**
     * Compare view: every selected president's debt change since taking office.
     */
    public function compareView(RequestModel $requestModel): string
    {
        $per = $requestModel->per();
        $dataset = $this->debtSource->load();
        $series = $this->compareService->buildSeries($requestModel->getComparePres(), $dataset, $per);
        $hasTrend = false;
        if ($series === []) {
            $results = $this->message('There is no Treasury data yet for the presidents you picked.');
        } else {
            $chart = ChartData::compare($series, $per);
            if (!$per->isTotal()) {
                $chart['divisor'] = $this->divisorInfo($per);
            }
            $hasTrend = array_filter(array_column($chart['series'], 'trend')) !== [];
            $results = $this->loadFile('chart_compare.php', [
                'series' => $series,
                'chart' => $chart,
                'show_trend' => $requestModel->showTrend(),
                'per' => $per,
                'footnote' => $this->censusFootnote($per, $this->compareService->firstDataDate($requestModel->getComparePres(), $dataset)),
            ]);
        }

        $share = $this->shareCards->forCompare($dataset, $requestModel->getComparePres(), $requestModel->showTrend(), $per);
        $viewQuery = 'compare=' . implode(',', $requestModel->getComparePres());
        return $this->page($requestModel, $results, $dataset, $this->debtTodayCard($dataset, $per), 'Compare presidential terms', $hasTrend, $share, $viewQuery);
    }

    /**
     * @param string $error  Plain text; it is escaped here.
     */
    public function getErrorView(string $error, RequestModel $requestModel): string
    {
        return $this->page($requestModel, $this->message($error, 'error'));
    }

    /**
     * @param list<array{label: string, value: string, detail: string}> $cards
     */
    private function page(
        RequestModel $requestModel,
        string $results,
        ?DebtDataset $dataset = null,
        array $cards = [],
        string $heading = '',
        bool $trendToggle = false,
        ?ShareCard $share = null,
        string $viewQuery = '',
    ): string {
        $notice = '';
        if ($dataset !== null && $dataset->isStale()) {
            $notice = $this->message(
                'Showing Treasury data saved on ' . $dataset->savedOn() . '. Newer data could not be loaded right now.',
                'notice'
            );
        }
        return $this->loadFile('master.php', [
            'title' => $heading === '' ? SiteConfig::NAME : $heading . ' - ' . SiteConfig::NAME,
            'site_name' => SiteConfig::NAME,
            'per_query' => $requestModel->per()->query(),
            'notice' => $notice,
            'per_switch' => $this->loadFile('per_switch.php', [
                'options' => self::perLinks($requestModel, $viewQuery),
                'label' => ShareText::PER_SWITCH_LABEL,
                'aria' => ShareText::PER_SWITCH_ARIA,
                'help' => ShareText::perHelp($requestModel->per()),
            ]),
            'cards' => $cards === [] ? '' : $this->loadFile('headline.php', ['cards' => $cards]),
            'controls' => $this->getSearchForm($requestModel, $viewQuery),
            'results_heading' => $heading !== '' ? $heading : ShareText::resultsHeading($requestModel->per()),
            'results' => $results,
            // The trend button shows only when the chart has a trend line to switch.
            'trend_toggle' => $trendToggle,
            'show_trend' => $requestModel->showTrend(),
            'share' => $this->shareMeta($share),
            // A page with something to share gets the Share button and menu.
            'share_menu' => $share === null ? '' : $this->loadFile('share_menu.php', $this->shareMenu($share)),
        ]);
    }

    /**
     * Link preview tags (Open Graph and Twitter) for this view. Every link is
     * absolute and built from the configured site address. A page with nothing
     * to share (an error, a range with no data) gets the site-wide preview.
     *
     * @return array{title: string, description: string, url: string, image: string, image_alt: string}
     */
    private function shareMeta(?ShareCard $share): array
    {
        if ($share === null) {
            return [
                'title' => ShareText::HOME_TITLE,
                'description' => ShareText::HOME_DESCRIPTION,
                'url' => $this->shareLinks->page(''),
                'image' => $this->shareLinks->card(''),
                'image_alt' => ShareText::HOME_ALT,
            ];
        }
        return [
            'title' => $share->title,
            'description' => $share->description,
            'url' => $this->shareLinks->page($share->pageQuery),
            'image' => $this->shareLinks->card($share->cardQuery),
            'image_alt' => $share->imageAlt,
        ];
    }

    /**
     * Everything the Share menu shows. The page link is the same as og:url;
     * the card links are root-relative (no host) so "Download picture" stays on
     * this site, and do not depend on the page's own path.
     *
     * @return array<string, mixed>
     */
    private function shareMenu(ShareCard $share): array
    {
        $url = $this->shareLinks->page($share->pageQuery);
        return [
            'url' => $url,
            'card_href' => $this->shareLinks->localCard($share->cardQuery),
            'card_trend' => $share->cardTrendMatters,
            'download_name' => $share->downloadName,
            'image_alt' => $share->imageAlt,
            'share_text' => $share->shareText,
            'email_subject' => $share->emailSubject,
            'email_note' => ShareText::emailNote($share->per),
            'networks' => ShareLinks::networks($url, $share->shareText, $share->emailSubject, $share->per),
        ];
    }

    /**
     * @return list<array{label: string, value: string, detail: string}>
     */
    private function debtTodayCard(DebtDataset $dataset, Per $per): array
    {
        $latest = $dataset->latest();
        if ($latest === null) {
            return [];
        }
        $divisor = $this->population()->divisor($per, $latest->effectiveDate);
        $held = $this->population()->held($per, $latest->effectiveDate) === PopulationService::HELD_LATEST
            ? ($this->population()->latest($per)['year'] ?? null)
            : null;
        return [[
            'label' => ShareText::todayLabel($per),
            'value' => Html::compactMoney($latest->totalDebt / $divisor),
            'detail' => ShareText::todayDetail($per, $latest->totalDebt, $divisor, $latest->effectiveDate, $held),
        ]];
    }

    private function population(): PopulationService
    {
        return $this->compareService->population();
    }

    /**
     * What the per person (or household) numbers are divided by, for the
     * page script: which Census figure, the day each yearly figure is pinned
     * to (MM-DD), and the last figure (after its day it is held, not projected).
     *
     * @return array{kind: string, anchor: string, latest_year: int|null, latest_date: string|null, latest_value: float|null}
     */
    private function divisorInfo(Per $per): array
    {
        $latest = $this->population()->latest($per);
        return [
            'kind' => $per === Per::Household ? 'households' : 'population',
            'anchor' => $per === Per::Household ? PopulationService::HOUSEHOLDS_ANCHOR : PopulationService::POPULATION_ANCHOR,
            'latest_year' => $latest['year'] ?? null,
            'latest_date' => $latest['date'] ?? null,
            'latest_value' => $latest['value'] ?? null,
        ];
    }

    private function message(string $text, string $class = 'message'): string
    {
        return '<p class="' . Html::e($class) . '">' . Html::e($text) . '</p>';
    }

    /**
     * @param string $viewQuery  This view's own link without per or trend: dates, compare picks, or '' (home).
     */
    private function getSearchForm(RequestModel $requestModel, string $viewQuery): string
    {
        $today = self::today();
        // Compare and error pages have no range of their own: prefill the default (last month).
        $end = $requestModel->getEndDate()?->format('Y-m-d') ?? $today->format('Y-m-d');
        $start = $requestModel->getStartDate()?->format('Y-m-d') ?? self::monthsBefore($today, 1)->format('Y-m-d');
        return $this->loadFile('search_form.php', [
            'pres_array' => PresidentService::getPresidentConfig(),
            'start_date' => $start,
            'end_date' => $end,
            'compare_pres' => $requestModel->getComparePres(),
            // Highlight a chip only when this page shows a date range (not on Compare or error pages).
            'quick_ranges' => self::quickRanges($today, $requestModel->getStartDate() === null ? '' : $start, $end),
            'today' => $today->format('Y-m-d'),
            'show_trend' => $requestModel->showTrend(),
            'per' => $requestModel->per(),
        ]);
    }

    /**
     * Footnote under the chart for per person and per household: the Census
     * counts and the year of the latest one ('' for the total).
     */
    private function censusFootnote(Per $per, ?string $firstDate): string
    {
        return ShareText::censusFootnote(
            $per,
            $per->isTotal() ? null : ($this->population()->latest($per)['year'] ?? null),
            $this->earlyHeldYear($per, $firstDate)
        );
    }

    /**
     * The year of the first Census figure when the view starts before it (so
     * that figure is used for the early days), else null.
     *
     * @param string|null $firstDate  The first day the view uses (Y-m-d).
     */
    private function earlyHeldYear(Per $per, ?string $firstDate): ?int
    {
        if ($per->isTotal() || $firstDate === null) {
            return null;
        }
        return $this->population()->held($per, $firstDate) === PopulationService::HELD_EARLIEST
            ? ($this->population()->earliest($per)['year'] ?? null)
            : null;
    }

    /**
     * The "Show numbers as" switch: this same view as the total, per person
     * and per household. Plain links, so they work without JavaScript. They
     * keep the dates (or Compare picks) and trend=0; the page script keeps
     * them in step with the trend switch. No #results jump: the switch sits
     * at the top, right above the headline numbers it changes.
     *
     * @return list<array{label: string, href: string, current: bool, value: string}>
     */
    private static function perLinks(RequestModel $requestModel, string $viewQuery): array
    {
        $links = [];
        foreach (Per::cases() as $per) {
            $query = implode('&', array_filter(
                [$viewQuery, $per->query(), $requestModel->showTrend() ? '' : 'trend=0'],
                static fn (string $p): bool => $p !== ''
            ));
            $links[] = [
                'label' => ShareText::PER_OPTIONS[$per->value],
                'href' => './' . ($query === '' ? '' : '?' . $query),
                'current' => $per === $requestModel->per(),
                'value' => $per->value,
            ];
        }
        return $links;
    }

    /**
     * Quick-range chips. Each is a plain link, so they work without JavaScript.
     *
     * @return list<array{label: string, title: string, start: string, end: string, current: bool}>
     */
    private static function quickRanges(DateTimeImmutable $today, string $start, string $end): array
    {
        $to = $today->format('Y-m-d');
        $ranges = [
            ['label' => '1M', 'title' => 'Last month', 'start' => self::monthsBefore($today, 1)->format('Y-m-d')],
            ['label' => '1Y', 'title' => 'Last year', 'start' => self::monthsBefore($today, 12)->format('Y-m-d')],
            ['label' => '5Y', 'title' => 'Last 5 years', 'start' => self::monthsBefore($today, 60)->format('Y-m-d')],
        ];
        foreach (PresidentService::getPresidentConfig() as $term) {
            $termStart = new DateTimeImmutable($term['start']);
            if ($termStart <= $today && $today <= new DateTimeImmutable($term['end'])) {
                $ranges[] = ['label' => 'This term', 'title' => 'Since ' . $termStart->format('F j, Y'), 'start' => $termStart->format('Y-m-d')];
                break;
            }
        }
        $out = [];
        foreach ($ranges as $range) {
            $range['end'] = $to;
            $range['current'] = $range['start'] === $start && $to === $end;
            $out[] = $range;
        }
        return $out;
    }

    /**
     * The same day of the month, $months months earlier. When that month is
     * shorter, use its last day: Mar 31 -> Feb 28 (Feb 29 in a leap year),
     * never Mar 3. PHP's modify('-1 month') rolls over into the next month.
     */
    public static function monthsBefore(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $target = $date->modify('first day of this month')->modify('-' . $months . ' months');
        $day = min((int) $date->format('j'), (int) $target->format('t'));
        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day);
    }

    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
    }

    /**
     * Render a template from View/ with the given variables and return the HTML.
     * If the template fails, its partial output is thrown away.
     *
     * @param array<string, mixed> $loadFile_data
     */
    private function loadFile(string $loadFile_file, array $loadFile_data = []): string
    {
        $loadFile_path = dirname(__DIR__) . '/View/' . basename($loadFile_file);
        extract($loadFile_data, EXTR_SKIP);
        ob_start();
        try {
            include $loadFile_path;
            return (string) ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
