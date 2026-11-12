<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('America/New_York');

/* Keep the app's error_log() lines out of the test output. */
$usdebtTmp = __DIR__ . '/.tmp';
if (!is_dir($usdebtTmp)) {
    mkdir($usdebtTmp, 0775, true);
}
ini_set('error_log', getenv('USDEBT_TEST_ERROR_LOG') ?: $usdebtTmp . '/error.log');
unset($usdebtTmp);
