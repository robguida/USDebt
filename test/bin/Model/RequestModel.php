<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 7:21 PM
 */

require_once '../../../src/bin/bootstrap.php';

use USDebt\Model\RequestModel;

$model = new RequestModel();

$model->setStartDate('This is a strring');
$result = $model->getStartDate();
echo(__FILE__ . ' ' . __LINE__ . ' $result:<pre>' . print_r($result, true) . '</pre>');
