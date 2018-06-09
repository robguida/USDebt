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
            $graph = 'tab_graph_Chart.php';
        } else {
            $datas = $datas->entries;
            $graph = 'tab_graph_Flot.php';
        }
        $tab_sections = [
            'data' => $this->loadFile('tab_data.php', ['datas' => $datas]),
            'graph' => $this->loadFile(
                $graph,
                ['datas' => $datas, 'pres_array' => PresidentService::getPresidentConfig()]
            ),
            'stats' => $output = $this->loadFile('tab_stats.php', ['datas' => $datas]),
        ];
        return $this->loadFile('tabs.php', $tab_sections);
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
