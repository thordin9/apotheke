<?php

declare(strict_types=1);

defined('ENVIRONMENT') || define('ENVIRONMENT', 'production');
defined('CI_DEBUG') || define('CI_DEBUG', false);
defined('APPPATH') || define('APPPATH', dirname(__DIR__, 2) . '/app/');
defined('ROOTPATH') || define('ROOTPATH', dirname(__DIR__, 2) . '/');
defined('FCPATH') || define('FCPATH', dirname(__DIR__, 2) . '/public/');

$ci4 = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($ci4)) {
    require_once $ci4;
}
if (!function_exists('service')) {
    function service(string $name, mixed ...$params): mixed
    {
        return null;
    }
}
if (!function_exists('config')) {
    function config(string $name): mixed
    {
        return null;
    }
}
require_once __DIR__ . '/NoServiceRule.php';
