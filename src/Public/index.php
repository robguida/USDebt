<?php
/** @var USDebt\Model\EnvModel $envModel */

$start_time = microtime(true);
$version = round(filemtime(__FILE__)/100000000, 3);

require_once('../lib/bootstrap.php');
use USDebt\Controller\USDebtController;
use USDebt\Model\RequestModel;

try {
    $action = $_REQUEST['submit'] ?? '';
    $controller = new USDebtController($envModel);
    $request = new RequestModel();

    switch (strtolower($action)) {
        case 'compare':
            $compare_pres = (!empty($_REQUEST['compare_pres'])) ? $_REQUEST['compare_pres'] : [];
            $request->setComparePres($compare_pres);
            $output = $controller->compareView($request);
            break;
        case 'fetch':
        default:
            $startDt = new DateTime();
            $start_date = $_GET['start_date'] ?? $startDt->modify('-1 month')->format('Y-m-d');
            $end_date = $_GET['end_date'] ?? date('Y-m-d');
            $request->setStartDate($start_date);
            $request->setEndDate($end_date);
            $output = $controller->defaultView($request);
    }

} catch (Exception $e) {
    $output = '$e:<pre>' . print_r($e, true) . '</pre>';
}
echo $output;
$end_time = microtime(true);
echo "<span style=\"font-size: xx-small; display: inline; margin-right:.5em;\">Test v.{$version}</span>";
printf('<span style="font-size: xx-small; display: inline;">TM%f</span>', $end_time - $start_time);
