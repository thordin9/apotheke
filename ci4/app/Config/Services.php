<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /** The Apotheke config (defaults < config.php < env), once per request. */
    public static function apothekeConfig(bool $getShared = true): \Apotheke\Core\Config\ApothekeConfig
    {
        if ($getShared) {
            return static::getSharedInstance('apothekeConfig');
        }

        return \Apotheke\Core\Config\ApothekeConfig::fromEnvironment();
    }

    /**
     * The CI4 connection. Migrations run once per process before it is used
     * (BootMigration), not on every request, and through apotheke:migrate.
     */
    public static function apothekeDb(bool $getShared = true): object
    {
        if ($getShared) {
            return static::getSharedInstance('apothekeDb');
        }
        $config = static::apothekeConfig();
        \Apotheke\Migrations\BootMigration::ensure($config);
        \Apotheke\Core\Boot\FrameworkWiring::apply($config);
        $factory = new \Apotheke\Core\Config\DatabaseFactory($config, new \Apotheke\Core\Config\DsnTranslator());
        $db = \Config\Database::connect();
        $factory->apply($db);

        return $db;
    }

    /**
     * Settings for this request. The shared instance is the per-request
     * cache: CI4 resets shared services between worker requests.
     */
    public static function settings(bool $getShared = true): \Apotheke\Settings\SettingsService
    {
        if ($getShared) {
            return static::getSharedInstance('settings');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Settings\SettingsService(
            new \Apotheke\Settings\SettingsRepository($db),
            new \Apotheke\Settings\SettingsSchema(),
            static::brandStore(),
            new \Apotheke\Core\Libraries\Clock(),
            static::apothekeConfig()->values(),
        );
    }

    /** Logo and icon files: DATA_DIR/brand on local disk. */
    public static function brandStore(bool $getShared = true): \Apotheke\Settings\BrandStore
    {
        if ($getShared) {
            return static::getSharedInstance('brandStore');
        }

        return new \Apotheke\Settings\LocalBrandStore(static::apothekeConfig()->dataDir() . '/brand');
    }

    /**
     * Theme, labels, manifest and favicon output. Bundled icons come from the
     * legacy public/assets until the Layout PR moves them under ci4/public.
     */
    public static function branding(bool $getShared = true): \Apotheke\Settings\Branding\Branding
    {
        if ($getShared) {
            return static::getSharedInstance('branding');
        }

        return new \Apotheke\Settings\Branding\Branding(
            static::settings(),
            static::brandStore(),
            new \Apotheke\Core\Libraries\AssetUrl(\Apotheke\Core\Config\ApothekeConfig::repoRoot() . '/public/assets'),
        );
    }
}
