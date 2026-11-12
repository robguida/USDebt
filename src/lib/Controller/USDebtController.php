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
     * Date-range view: graph, stats and data tabs. Defaults to the last month.
     */
    public function defaultView(RequestModel $requestModel): string
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('America/New_York'));
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

        if ($entries === []) {
            $tabData = $this->message(sprintf(
                'There is no Treasury data for this date range. Data runs from %s to %s.',
                $dataset->firstDate() ?? '?',
                $dataset->lastDate() ?? '?'
            ));
        } else {
            $tabData = $this->loadFile('tabs.php', [
                'data' => $this->loadFile('tab_data.php', ['datas' => array_reverse($entries)]),
                'graph' => $this->loadFile('tab_graph_Chart.php', ['chart' => ChartData::daily($entries)]),
                'stats' => $this->loadFile('tab_stats.php', ['stats' => StatsCalculator::summarize($entries)]),
            ]);
        }

        return $this->page($requestModel, $tabData, $dataset);
    }

    /**
     * Compare view: every selected president's debt change since taking office.
     */
    public function compareView(RequestModel $requestModel): string
    {
        $dataset = $this->debtSource->load();
        $series = $this->compareService->buildSeries($requestModel->getComparePres(), $dataset);
        $tabData = $series === []
            ? $this->message('There is no Treasury data yet for the presidents you picked.')
            : $this->loadFile('tab_graph_Flot.php', ['series' => $series]);

        return $this->page($requestModel, $tabData, $dataset);
    }

    /**
     * @param string $error  Plain text; it is escaped here.
     */
    public function getErrorView(string $error, RequestModel $requestModel): string
    {
        return $this->page($requestModel, $this->message($error, 'error'));
    }

    private function page(RequestModel $requestModel, string $tabData, ?DebtDataset $dataset = null): string
    {
        $notice = '';
        if ($dataset !== null && $dataset->isStale()) {
            $notice = $this->message(
                'Showing Treasury data saved on ' . $dataset->savedOn() . '. Newer data could not be loaded right now.',
                'notice'
            );
        }
        return $this->loadFile('master.php', ['main_content' => [
            'search_form' => $this->getSearchForm($requestModel),
            'tab_data' => $notice . $tabData,
        ]]);
    }

    private function message(string $text, string $class = 'message'): string
    {
        return '<p class="' . Html::e($class) . '">' . Html::e($text) . '</p>';
    }

    private function getSearchForm(RequestModel $requestModel): string
    {
        return $this->loadFile('search_form.php', [
            'pres_array' => PresidentService::getPresidentConfig(),
            'start_date' => $requestModel->getStartDate()?->format('Y-m-d') ?? '',
            'end_date' => $requestModel->getEndDate()?->format('Y-m-d') ?? '',
            'compare_pres' => $requestModel->getComparePres(),
        ]);
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
