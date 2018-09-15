<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/5/18
 * Time: 6:15 AM
 */

namespace USDebt\Service;

use stdClass;

class TreasuryDirect
{
    /**
     * @param string $start_date
     * @param string $end_date
     * @param bool $raw  return raw data from the request
     * @return stdClass|string
     */
    public static function httpRequest($start_date, $end_date, $raw = false)
    {
        $dot_url = 'https://www.treasurydirect.gov/NP_WS/debt/search?' .
            "startdate={$start_date}&enddate={$end_date}&format=json";
        $cache_key = md5(__METHOD__ . "_{$dot_url}");
        if (function_exists('acp_exists')) {
            if (!apc_exists($cache_key)) {
                $response = file_get_contents($dot_url);
                apc_add($cache_key, $response);
            } else {
                $response = apc_fetch($cache_key);
            }
        } else {
            $response = file_get_contents($dot_url);
        }
        if ($response && !$raw) {
            $response = json_decode($response);
            $response = $response->entries;
        }
        return $response;
    }
}
