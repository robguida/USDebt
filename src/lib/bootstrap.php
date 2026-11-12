<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 6:06 PM
 */

declare(strict_types=1);

/*
 * Errors are always logged. They are shown on the page only when the
 * environment variable USDEBT_DEV=1 is set (local development).
 * No session is started: the site has no login and stores nothing per visitor.
 */
error_reporting(E_ALL);
$usdebtDev = getenv('USDEBT_DEV') === '1';
ini_set('display_errors', $usdebtDev ? '1' : '0');
ini_set('display_startup_errors', $usdebtDev ? '1' : '0');
ini_set('log_errors', '1');
unset($usdebtDev);

date_default_timezone_set('America/New_York');

/* USDebt\Foo\Bar -> src/lib/Foo/Bar.php. Other namespaces are left to other loaders. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'USDebt\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
