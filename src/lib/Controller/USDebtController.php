<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 6:05 PM
 */

namespace USDebt\Controller;

use DateTime;
use Exception;
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

        /* Set up the tab array */
        $tab_sections = [
            'data' => $this->loadFile('tab_data.php', ['datas' => $datas]),
            'graph' => $this->loadFile(
                'tab_graph_Chart.php',
                ['datas' => $datas, 'pres_array' => PresidentService::getPresidentConfig()]
            ),
            'stats' => $output = $this->loadFile('tab_stats.php', ['datas' => $datas]),
        ];

        /* Set up the data for the main template */
        $main_content = [];
        $main_content['search_form'] = $this->getSearchForm($requestModel);
        $main_content['tab_data'] = $this->loadFile('tabs.php', $tab_sections);

        /* Load up the master template */
        return $this->loadFile('master.php', ['main_content' => $main_content ]);
    }

    /**
     * @param RequestModel $requestModel
     * @return string
     * @throws Exception
     */
    public function compareView(RequestModel $requestModel)
    {
        $main_content = [];
        /* get the search form */
        $main_content['search_form'] = $this->getSearchForm($requestModel);
        /* get the presidents to compare and then find the start and end dates */
        if ($compares = $requestModel->getComparePres()) {
            error_log(__METHOD__ . ' $compares<pre>' . print_r($compares, true) . '</pre>');
            $selected_pres = [];
            foreach ($compares as $c) {
                $pres_config = PresidentService::getPresident($c);
                error_log(__METHOD__ . ' $pres_config<pre>' . print_r($pres_config, true) . '</pre>');
                $startDate = new DateTime($pres_config['start']);
                $endDate = new DateTime($pres_config['end']);
                error_log(__METHOD__ . ' $startDate<pre>' . print_r($startDate, true) . '</pre>');
                error_log(__METHOD__ . ' $endDate<pre>' . print_r($endDate, true) . '</pre>');
                $selected_pres[$c] = [
                    'config' => $pres_config,
                    'datas' => TreasuryDirect::httpRequest($startDate->format('Y-m-d'), $endDate->format('Y-m-d'))
                ];
                error_log(__METHOD__ . ' $selected_pres[$c][datas]<pre>' . print_r(current($selected_pres[$c]['datas']), true) . '</pre>');
            }
            $main_content['tab_data'] = $this->loadFile('tab_graph_Flot.php', ['datas' => $selected_pres]);
        } else {
            $main_content['tab_data'] ='Nothing to compare';
        }
        /* Load up the master template */
        return $this->loadFile('master.php', ['main_content' => $main_content ]);
    }

    /**
     * @param string $error
     * @param RequestModel $requestModel
     * @return string
     */
    public function getErrorView($error, RequestModel $requestModel)
    {
        /* get the search form */
        $main_content = [];
        $main_content['search_form'] = $this->getSearchForm($requestModel);
        $main_content['tab_data'] = $error;

        /* Load up the master template */
        return $this->loadFile('master.php', ['main_content' => $main_content ]);
    }

    /**
     * @param RequestModel $requestModel
     * @return string
     */
    private function getSearchForm(RequestModel $requestModel)
    {
        $view_data = ['pres_array' => PresidentService::getPresidentConfig(), 'start_date' => '', 'end_date' => ''];
        if ($requestModel->getStartDate(true) instanceof DateTime) {
            $view_data['start_date'] = $requestModel->getStartDate()->format('Y-m-d');
        }
        if ($requestModel->getEndDate(true) instanceof DateTime) {
             $view_data['end_date'] = $requestModel->getEndDate()->format('Y-m-d');
        }
        if ($compare_pres = $requestModel->getComparePres()) {
            $view_data['compare_pres'] = $compare_pres;
        }
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
