<?php

$starttime = microtime(true);
$version = round(filemtime(__FILE__)/100000000, 3);
require_once('../lib/bootstrap.php');

use USDebt\Controller\USDebtController;
use test\USDebt\Model\RequestModel;

try {
    $action = isset($_REQUEST['submit']) ? $_REQUEST['submit'] : '';
    $controller = new USDebtController();
    $request = new RequestModel();
    try {
        switch (strtolower($action)) {
            case 'compare':
                $request->setComparePres($_REQUEST['compare_pres']);
                $output = $controller->compareView($request);
                break;
            case 'fetch':
            default:
                $startDt = new DateTime();
                $start_date = isset($_GET['start_date']) ?
                    $_GET['start_date'] : $startDt->modify('-1 month')->format('Y-m-d');
                $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

                $request->setStartDate($start_date);
                $request->setEndDate($end_date);
                $output = $controller->defaultView($request);
        }
    } catch (Exception $e) {
        /* Try to gracefully catch the exception */
        $output = $controller->getErrorView($e->getMessage(), $request);
    }
} catch (Exception $e) {
    $output = '$e:<pre>' . print_r($e, true) . '</pre>';
}
echo $output;
$endtime = microtime(true);
echo "<span style=\"font-size: xx-small; display: inline; margin-right:.5em;\">v.{$version}</span>";
printf('<span style="font-size: xx-small; display: inline;">TM%f</span>', $endtime - $starttime);
