<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 6:05 PM
 */

namespace USDebt\Controller;

use DateTime;
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
        $datas = TreasuryDirect::httpRequest(
            $requestModel->getStartDate(true)->format('Y-m-d'),
            $requestModel->getEndDate(true)->format('Y-m-d')
        );
        $view_data = [
            'main_content' => '',
            'pres_array' => (new PresidentService())->getPresidentConfig(),
        ];
        return $this->loadFile('master.php', $view_data);
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
