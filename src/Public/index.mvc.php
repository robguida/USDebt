<?php

require_once('../bin/bootstrap.php');

use USDebt\Controller\USDebtController;
use USDebt\Model\RequestModel;

try {
    $controller = new USDebtController();
    echo(__FILE__ . ' ' . __LINE__ . ' $controller:<pre>' . print_r($controller, true) . '</pre>');
    $request = new RequestModel();
    $request->setStartDate($_GET['start_date']);
    $request->setEndDate($_GET['end_date']);
    echo(__FILE__ . ' ' . __LINE__ . ' $request:<pre>' . print_r($request, true) . '</pre>');
    $controller->defaultView($request);
} catch (Exception $e) {
    echo(__FILE__ . ' ' . __LINE__ . ' $e:<pre>' . print_r($e, true) . '</pre>');
}
