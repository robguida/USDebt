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
        $tab_sections = [
            'data' => $this->loadFile('tab_data.php', ['tab_data' => $datas]),
            'graph' => $this->loadFile('tab_graph_Chart.php', ['datas' => $datas]),
            //'stats' => $this->loadFile('tab_stats.php', ['datas' => $datas]),
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
            'pres_array' => (new PresidentService())->getPresidentConfig(),
            'start_date' => $requestModel->getStartDate()->format('Y-m-d'),
            'end_date' => $requestModel->getEndDate()->format('Y-m-d'),
        ];
        return $this->loadFile('search_form.php', $view_data);
    }

    /**
     * @param string $file
     * @param array $datas
     * @return string
     */
    private function loadFile($file, array $datas = null)
    {
        ob_start();
        $full_path = "View/{$file}";
        if (!is_null($datas) && 0 < count($datas)) {
            $i = 0;
            foreach ($datas as $variable => $param) {
                unset($datas[$i++]);
                $$variable = $param; // create the new variable using the class name
            }
            unset($datas);// no longer needed
        }
        include($full_path);
        $output = ob_get_contents();
        ob_clean();
        return $output;
    }
}
