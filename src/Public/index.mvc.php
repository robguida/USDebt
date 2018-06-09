<?php

$starttime = microtime(true);
require_once('../bin/bootstrap.php');

use USDebt\Controller\USDebtController;
use USDebt\Model\RequestModel;

try {
    $startDt = new DateTime();
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $startDt->modify('-1 month')->format('Y-m-d');
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

    $controller = new USDebtController();
    $request = new RequestModel();
    $request->setStartDate($start_date);
    $request->setEndDate($end_date);
    echo $controller->defaultView($request);
} catch (Exception $e) {
    echo(__FILE__ . ' ' . __LINE__ . ' $e:<pre>' . print_r($e, true) . '</pre>');
}
$endtime = microtime(true);
printf("Page loaded in %f seconds", $endtime - $starttime );
