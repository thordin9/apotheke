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
if (!function_exists('helper')) {
    function helper(string|array $filenames): void
    {
    }
}
if (!function_exists('redirect')) {
    function redirect(?string $route = null): CodeIgniter\HTTP\RedirectResponse
    {
        return new CodeIgniter\HTTP\RedirectResponse(new Config\App());
    }
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
if (!function_exists('apotheke_migration_cfg')) {
    function apotheke_migration_cfg(array $cfg): void
    {
    }
}
if (!function_exists('config_dsn')) {
    /** @param array<string,mixed> $db */
    function config_dsn(array $db): string
    {
        return '';
    }
}
if (!function_exists('config_migrate_where')) {
    /** @param array<string,mixed> $db */
    function config_migrate_where(array $db): string
    {
        return '';
    }
}
if (!function_exists('config_dsn_public_fields')) {
    /** @return array<string,string>|null */
    function config_dsn_public_fields(string $dsn): ?array
    {
        return null;
    }
}
if (!function_exists('config_dsn_keywords')) {
    /** @return array<string,string>|null */
    function config_dsn_keywords(string $rest): ?array
    {
        return null;
    }
}
if (!function_exists('now_utc')) {
    function now_utc(): string
    {
        return '';
    }
}
if (!function_exists('legacy_migrate_to_v2')) {
    function legacy_migrate_to_v2(PDO $pdo, int $v): void
    {
    }
}
if (!function_exists('legacy_migrate_to_v3')) {
    function legacy_migrate_to_v3(PDO $pdo): void
    {
    }
}
if (!function_exists('seed_defaults')) {
    function seed_defaults(PDO $pdo, bool $force = false): int
    {
        return 0;
    }
}
if (!defined('LEGACY_SCHEMA_VERSION')) {
    define('LEGACY_SCHEMA_VERSION', 3);
}
require_once __DIR__ . '/NoServiceRule.php';
