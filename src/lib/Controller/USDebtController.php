<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 6:05 PM
 */

namespace USDebt\Controller;

use DateTime;
use stdClass;
use USDebt\Model\RequestModel;
use USDebt\Service\PresidentService;
use USDebt\Service\TreasuryDirect;

class USDebtController
{
    /**
     * @param RequestModel $requestModel
     * @return string
     */
    public function defaultView(RequestModel $requestModel)
    {
        $now = new DateTime();
        if (is_null($requestModel->getStartDate())) {
            $requestModel->setStartDate($now->modify('-1 month'));
        }
        if (is_null($requestModel->getEndDate())) {
            $requestModel->setEndDate($now);
        }

        /** @var stdClass $datas */
        $datas = TreasuryDirect::httpRequest(
            $requestModel->getStartDate(true)->format('Y-m-d'),
            $requestModel->getEndDate(true)->format('Y-m-d')
        );

        /* get the search form */
        $main_content['search_form'] = $this->getSearchForm($requestModel);
        $main_content['tab_data'] = $this->getTabData($datas);

        /* Load up the master template */
        $view_data = [
            'main_content' => $main_content,
        ];
        return $this->loadFile('master.php', $view_data);
    }

    /**
     * @param stdClass $datas
     * @return string
     */
    private function getTabData(stdClass $datas)
    {
        if (isset($datas->entries)) {
            $datas = $datas->entries;
        } else {
            //
        }
        $tab_sections = [
            'data' => $this->loadFile('tab_data.php', ['datas' => $datas]),
            'graph' => $this->loadFile(
                'tab_graph_Chart.php',
                ['datas' => $datas, 'pres_array' => PresidentService::getPresidentConfig()]
            ),
            'stats' => $this->getStatsData($datas),
        ];
        return $this->loadFile('tabs.php', $tab_sections);
    }

    private function getStatsData(array $datas)
    {
        /* we need the first and last debt to get the delta,
            current($data)and to compute the debt per day for the date range */
        /* we need the first and last debt to get the delta, and to compute the debt per day for the date range */
        $lastDebt = current($datas);
        $firstDebt = end($datas);
        $firstDate = new DateTime($lastDebt->effectiveDate);
        $lastDate = new DateTime($firstDebt->effectiveDate);
        $working_days = count($datas);
        $days = $firstDate->diff($lastDate)->days;
        $delta = $lastDebt->totalDebt - $firstDebt->totalDebt;
        $average_per_day = round($delta / $working_days, 2);
        if (0 > $delta) {
            $delta_str = '-$' . number_format(abs($delta), 2) . '';
        } else {
            $delta_str = '$' . number_format($delta, 2);
        }
        if (0 > $average_per_day) {
            $average_per_day_str = '-$' . number_format(abs($average_per_day), 2) . '';
        } else {
            $average_per_day_str = '$' . number_format($average_per_day, 2);
        }
        $days_lt_start_debt = [];
        $days_gt_start_debt = [];
        $average_per_time_span = 0;
        foreach ($datas as $d) {
            /* if the amount is greather than the starting date amount, then add it to the array of days */
            if ($firstDebt->totalDebt < $d->totalDebt) {
                $days_gt_start_debt[] = $d;
            }
            /* if the amount is less than the starting date amount, then add it to the array of days */
            if ($firstDebt->totalDebt > $d->totalDebt) {
                $days_lt_start_debt[] = $d;
            }
            /* enter the debt values into an array to get the average */
            $average_per_time_span += $d->totalDebt;
        }

        /* calculate greater than debt */
        $days_gt_start_debt_count = count($days_gt_start_debt);
        $days_gt_start_debt_percentage = round(($days_gt_start_debt_count / $working_days) * 100, 2);

        /* calculate greater than debt */
        $days_lt_start_debt_count = count($days_lt_start_debt);
        $days_lt_start_debt_percentage = round(($days_lt_start_debt_count / $working_days) * 100, 2);

        /* calucate the average for the time span */
        $average_per_time_span /= $working_days;

        $view_data = [
            'last_debt' => $lastDebt,
            'first_debt' => $firstDebt,
            'firstDate' => new DateTime($lastDebt->effectiveDate),
            'lastDate' => new DateTime($firstDebt->effectiveDate),
            'working_days' => $working_days,
            'average_per_time_span' => $average_per_time_span,
            'days_gt_start_debt_count' => $days_gt_start_debt_count,
            'days_gt_start_debt_percentage' => $days_gt_start_debt_percentage,
            'days_lt_start_debt_count' => $days_lt_start_debt_count,
            'days_lt_start_debt_percentage' => $days_lt_start_debt_percentage,
            'delta_str' => $delta_str,
            'average_per_day_str' => $average_per_day_str,
            'days' => $days,
        ];
        $output = $this->loadFile('tab_stats.php', $view_data);
        return $output;
    }

    /**
     * @param RequestModel $requestModel
     * @return string
     */
    private function getSearchForm(RequestModel $requestModel)
    {
        $view_data = [
            'pres_array' => PresidentService::getPresidentConfig(),
            'start_date' => $requestModel->getStartDate()->format('Y-m-d'),
            'end_date' => $requestModel->getEndDate()->format('Y-m-d'),
        ];
        return $this->loadFile('search_form.php', $view_data);
    }

    /**
     * @param string $loadFile_file
     * @param array $loadFile_data
     * @return string
     */
    private function loadFile($loadFile_file, array $loadFile_data = null)
    {
        ob_start();
        $loadFile_file = "View/{$loadFile_file}";
        if (!is_null($loadFile_data) && 0 < count($loadFile_data)) {
            $i = 0;
            foreach ($loadFile_data as $loadFile_variable => $loadFile_value) {
                unset($loadFile_data[$i++]);
                $$loadFile_variable = $loadFile_value; // create the new variable using the class name
            }
            unset($loadFile_data);// no longer needed
        }
        include($loadFile_file);
        $output = ob_get_contents();
        ob_clean();
        return $output;
    }
}
