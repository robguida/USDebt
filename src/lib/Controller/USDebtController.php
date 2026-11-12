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
use USDebt\Model\RequestModel;
use USDebt\Model\ShareCard;
use USDebt\Service\ChartData;
use USDebt\Service\CompareService;
use USDebt\Service\DebtSource;
use USDebt\Service\Html;
use USDebt\Service\PresidentService;
use USDebt\Service\ShareCardBuilder;
use USDebt\Service\ShareLinks;
use USDebt\Service\ShareText;
use USDebt\Service\StatsCalculator;

class USDebtController
{
    public const SITE_TITLE = ShareText::SITE_NAME;

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

        $dataset = $this->debtSource->load();
        $entries = $dataset->between($requestModel->getStartDate(), $requestModel->getEndDate());
        $cards = $this->debtTodayCard($dataset);
        $hasTrend = false;

        if ($entries === []) {
            $results = $this->message(sprintf(
                'There is no Treasury data for this date range. Data runs from %s to %s.',
                $dataset->firstDate() ?? '?',
                $dataset->lastDate() ?? '?'
            ));
        } else {
            $stats = StatsCalculator::summarize($entries);
            $first = $entries[0];
            $last = $entries[count($entries) - 1];
            if ($stats !== null) {
                $cards[] = [
                    'label' => 'Change in this range',
                    'value' => Html::compactMoney($stats['delta'], true),
                    'detail' => Html::shortDate($first->effectiveDate) . ' to ' . Html::shortDate($last->effectiveDate),
                ];
                $cards[] = [
                    'label' => 'Per day',
                    'value' => Html::compactMoney($stats['average_per_day'], true),
                    'detail' => 'Average over ' . number_format($stats['total_days']) . ' calendar days',
                ];
            }
            $chart = ChartData::daily($entries);
            $hasTrend = in_array('trend', array_column($chart['series'], 'key'), true);
            $results = $this->loadFile('tabs.php', [
                'graph' => $this->loadFile('chart_daily.php', [
                    'chart' => $chart,
                    'first' => $first,
                    'last' => $last,
                    'show_trend' => $requestModel->showTrend(),
                ]),
                'stats' => $this->loadFile('tab_stats.php', ['stats' => $stats]),
                'data' => $this->loadFile('tab_data.php', ['datas' => array_reverse($entries)]),
            ]);
        }

        $share = $this->shareCards->forRange(
            $dataset,
            $requestModel->getStartDate(),
            $requestModel->getEndDate(),
            $requestModel->showTrend(),
            $explicitDates,
        );
        return $this->page($requestModel, $results, $dataset, $cards, '', $hasTrend, $share);
    }

    /**
     * Compare view: every selected president's debt change since taking office.
     */
    public function compareView(RequestModel $requestModel): string
    {
        $dataset = $this->debtSource->load();
        $series = $this->compareService->buildSeries($requestModel->getComparePres(), $dataset);
        $hasTrend = false;
        if ($series === []) {
            $results = $this->message('There is no Treasury data yet for the presidents you picked.');
        } else {
            $chart = ChartData::compare($series);
            $hasTrend = array_filter(array_column($chart['series'], 'trend')) !== [];
            $results = $this->loadFile('chart_compare.php', [
                'series' => $series,
                'chart' => $chart,
                'show_trend' => $requestModel->showTrend(),
            ]);
        }

        $share = $this->shareCards->forCompare($dataset, $requestModel->getComparePres(), $requestModel->showTrend());
        return $this->page($requestModel, $results, $dataset, $this->debtTodayCard($dataset), 'Compare presidential terms', $hasTrend, $share);
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
    ): string {
        $notice = '';
        if ($dataset !== null && $dataset->isStale()) {
            $notice = $this->message(
                'Showing Treasury data saved on ' . $dataset->savedOn() . '. Newer data could not be loaded right now.',
                'notice'
            );
        }
        return $this->loadFile('master.php', [
            'title' => $heading === '' ? self::SITE_TITLE : $heading . ' - ' . self::SITE_TITLE,
            'notice' => $notice,
            'cards' => $cards === [] ? '' : $this->loadFile('headline.php', ['cards' => $cards]),
            'controls' => $this->getSearchForm($requestModel),
            'results_heading' => $heading,
            'results' => $results,
            // The trend button shows only when the chart has a trend line to switch.
            'trend_toggle' => $trendToggle,
            'show_trend' => $requestModel->showTrend(),
            'share' => $this->shareMeta($share),
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
                'title' => ShareText::SITE_NAME,
                'description' => ShareText::SITE_DESCRIPTION,
                'url' => $this->shareLinks->page(''),
                'image' => $this->shareLinks->card(''),
                'image_alt' => ShareText::imageAlt(ShareText::SITE_NAME),
            ];
        }
        return [
            'title' => $share->headline,
            'description' => $share->description,
            'url' => $this->shareLinks->page($share->pageQuery),
            'image' => $this->shareLinks->card($share->cardQuery),
            'image_alt' => ShareText::imageAlt($share->headline),
        ];
    }

    /**
     * @return list<array{label: string, value: string, detail: string}>
     */
    private function debtTodayCard(DebtDataset $dataset): array
    {
        $latest = $dataset->latest();
        if ($latest === null) {
            return [];
        }
        return [[
            'label' => 'Debt today',
            'value' => Html::compactMoney($latest->totalDebt),
            'detail' => Html::money($latest->totalDebt) . ' on ' . Html::shortDate($latest->effectiveDate),
        ]];
    }

    private function message(string $text, string $class = 'message'): string
    {
        return '<p class="' . Html::e($class) . '">' . Html::e($text) . '</p>';
    }

    private function getSearchForm(RequestModel $requestModel): string
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
        ]);
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
