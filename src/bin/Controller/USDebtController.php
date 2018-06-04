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

class USDebtController
{
    public function defaultView(RequestModel $requestModel)
    {
        $now = new DateTime();
        if (is_null($requestModel->getStartDate())) {
            $requestModel->setStartDate($now->modify('-1 month'));
        }
        if (is_null($requestModel->getEndDate())) {
            $requestModel->setEndDate($now);
        }
        $datas = $this->httpRequest(
            $requestModel->getStartDate(true)->format('Y-m-d'),
            $requestModel->getEndDate(true)->format('Y-m-d')
        );
        echo(__FILE__ . ' ' . __LINE__ . ' $datas:<pre>' . print_r($datas, true) . '</pre>');
    }

    /**
     * @param string $start_date
     * @param string $end_date
     * @return mixed|string
     */
    private function httpRequest($start_date, $end_date)
    {
        $dot_url = 'https://www.treasurydirect.gov/NP_WS/debt/search?' .
            "startdate={$start_date}&enddate={$end_date}&format=json";
        $cache_key = md5($dot_url);
        echo(__FILE__ . ' ' . __LINE__ . ' $cache_key: ' . $cache_key . '<br />');
        if (!apc_exists($cache_key)) {
            $response = file_get_contents($dot_url);
            apc_add($cache_key, $response);
        } else {
            $response = apc_fetch($cache_key);
        }
        if ($response) {
            $response = json_decode($response);
        }
        return $response;
    }
}
