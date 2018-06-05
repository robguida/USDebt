<?php

require_once('../bin/bootstrap.php');

use USDebt\Controller\USDebtController;
use USDebt\Model\RequestModel;

try {
    $controller = new USDebtController();
    $request = new RequestModel();
    $request->setStartDate($_GET['start_date']);
    $request->setEndDate($_GET['end_date']);
    echo $controller->defaultView($request);
} catch (Exception $e) {
    echo(__FILE__ . ' ' . __LINE__ . ' $e:<pre>' . print_r($e, true) . '</pre>');
}
