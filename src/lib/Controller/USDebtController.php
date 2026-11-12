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
use USDebt\Service\ChartData;
use USDebt\Service\CompareService;
use USDebt\Service\DebtSource;
use USDebt\Service\Html;
use USDebt\Service\PresidentService;
use USDebt\Service\StatsCalculator;

class USDebtController
{
    public const SITE_TITLE = 'Our U.S. Debt';

    public function __construct(
        private readonly DebtSource $debtSource,
        private readonly CompareService $compareService,
    ) {
    }

    public function compareService(): CompareService
    {
        return $this->compareService;
    }

    /**
     * Date-range view: headline numbers, then chart, stats and table. Defaults to the last month.
     */
    public function defaultView(RequestModel $requestModel): string
    {
        $today = self::today();
        if ($requestModel->getEndDate() === null) {
            $requestModel->setEndDate($today);
        }
        if ($requestModel->getStartDate() === null) {
            $requestModel->setStartDate($requestModel->getEndDate()->modify('-1 month'));
        }
        if ($requestModel->getStartDate() > $requestModel->getEndDate()) {
            throw new UserFacingException('The start date must be on or before the end date.');
        }

        $dataset = $this->debtSource->load();
        $entries = $dataset->between($requestModel->getStartDate(), $requestModel->getEndDate());
        $cards = $this->debtTodayCard($dataset);

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
            $results = $this->loadFile('tabs.php', [
                'graph' => $this->loadFile('chart_daily.php', [
                    'chart' => ChartData::daily($entries),
                    'first' => $first,
                    'last' => $last,
                ]),
                'stats' => $this->loadFile('tab_stats.php', ['stats' => $stats]),
                'data' => $this->loadFile('tab_data.php', ['datas' => array_reverse($entries)]),
            ]);
        }

        return $this->page($requestModel, $results, $dataset, $cards);
    }

    /**
     * Compare view: every selected president's debt change since taking office.
     */
    public function compareView(RequestModel $requestModel): string
    {
        $dataset = $this->debtSource->load();
        $series = $this->compareService->buildSeries($requestModel->getComparePres(), $dataset);
        $results = $series === []
            ? $this->message('There is no Treasury data yet for the presidents you picked.')
            : $this->loadFile('chart_compare.php', ['series' => $series, 'chart' => ChartData::compare($series)]);

        return $this->page($requestModel, $results, $dataset, $this->debtTodayCard($dataset), 'Compare presidential terms');
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
        ]);
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
        $start = $requestModel->getStartDate()?->format('Y-m-d') ?? $today->modify('-1 month')->format('Y-m-d');
        return $this->loadFile('search_form.php', [
            'pres_array' => PresidentService::getPresidentConfig(),
            'start_date' => $start,
            'end_date' => $end,
            'compare_pres' => $requestModel->getComparePres(),
            // Highlight a chip only when this page shows a date range (not on Compare or error pages).
            'quick_ranges' => self::quickRanges($today, $requestModel->getStartDate() === null ? '' : $start, $end),
            'today' => $today->format('Y-m-d'),
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
            ['label' => '1M', 'title' => 'Last month', 'start' => $today->modify('-1 month')->format('Y-m-d')],
            ['label' => '1Y', 'title' => 'Last year', 'start' => $today->modify('-1 year')->format('Y-m-d')],
            ['label' => '5Y', 'title' => 'Last 5 years', 'start' => $today->modify('-5 years')->format('Y-m-d')],
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
