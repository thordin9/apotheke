<?php

declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('app_version')) {
    function app_version(): string
    {
        return \Apotheke\Core\Libraries\Version::current();
    }
}
