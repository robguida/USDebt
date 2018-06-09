<?php
/**
 * Created by PhpStorm.
 * User: robert
 * Date: 6/3/18
 * Time: 6:06 PM
 */
error_reporting(E_ALL);
session_start([
    'use_strict_mode' => true,
    'use_only_cookies' => true,
    'cookie_httponly' => true,
    'session_switch' => true,
    'cookie_lifetime' => 54000, // 15 minutes
]);
session_regenerate_id(true);
date_default_timezone_set('America/New_York');
spl_autoload_register('autoLoader');
ini_set('include_path', __DIR__);

function autoLoader($class)
{
    static $autoLoaderCache;
    if (is_null($autoLoaderCache)) {
        $autoLoaderCache = array();
    }
    if (!in_array($class, $autoLoaderCache)) {
        $autoLoaderCache[] = $class;
        $class = implode('/', array_slice(explode("\\", $class), -2, 2));
        $file = __DIR__ . "/{$class}.php";
        if (file_exists("$file")) {
            require($file);
        } else {
            throw new Exception("The namespace for '{$class}' resolves to '{$file}', which does not exist!");
        }
    }
}
