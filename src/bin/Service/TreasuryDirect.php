<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 6:15 AM
 */

namespace USDebt\Service;

class TreasuryDirect
{
    /**
     * @param string $start_date
     * @param string $end_date
     * @return mixed|string
     */
    public static function httpRequest($start_date, $end_date)
    {
        $dot_url = 'https://www.treasurydirect.gov/NP_WS/debt/search?' .
            "startdate={$start_date}&enddate={$end_date}&format=json";
        $cache_key = md5($dot_url);
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
