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
        if (PHP_SAPI === 'cli') {
            // spark fires no pre_system; on the web Events.php has already wired the group.
            \Apotheke\Core\Boot\FrameworkWiring::apply($config);
        }
        $dbConfig = $config->database();
        $factory = new \Apotheke\Core\Config\DatabaseFactory($config, new \Apotheke\Core\Config\DsnTranslator());
        try {
            $db = \Config\Database::connect();
            $factory->apply($db);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            // CI4 connects lazily, on apply()'s first query. A refused connect carries
            // libpq's text, which names the user. Report it as the sanitized line.
            if (($dbConfig['driver'] ?? '') !== 'pgsql' || !isset($db) || $db->connID !== false) {
                throw $e;
            }
            throw new \Apotheke\Migrations\MigrateConnectException(\Apotheke\Migrations\PdoMigrationRunner::connectFailure($dbConfig));
        }

        return $db;
    }

    /**
     * Settings for this request. The shared instance is the per-request
     * cache: CI4 resets shared services between worker requests. $actor
     * decides who may import sign-in settings (SEC-M1): the request's user
     * by default, or the command line for spark.
     */
    public static function settings(bool $getShared = true, ?\Apotheke\Core\Security\ActorGate $actor = null): \Apotheke\Settings\SettingsService
    {
        if ($getShared && $actor === null) {
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
            null,
            $actor ?? static::currentUser(),
        );
    }

    /**
     * The request's user (legacy current_user()). It keeps nothing: each call
     * reads this request's $_SESSION and SettingsService again.
     */
    public static function currentUser(bool $getShared = true): \Apotheke\Auth\CurrentUser
    {
        if ($getShared) {
            return static::getSharedInstance('currentUser');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Auth\CurrentUser(
            static fn (): \Apotheke\Settings\SettingsService => static::settings(),
            new \Apotheke\Auth\UserRepository($db),
            static fn (): array => $_SESSION ?? [],
        );
    }

    /**
     * Session cookie lifetime from session_days (SEC-N9). It keeps no value:
     * each seconds() call reads through this request's SettingsService.
     * SessionCookie asks for a new instance (false) every time.
     */
    public static function sessionLifetime(bool $getShared = true): \Apotheke\Core\Http\SessionLifetime
    {
        if ($getShared) {
            return static::getSharedInstance('sessionLifetime');
        }

        return new \Apotheke\Core\Http\SessionLifetime(static fn (): mixed => static::settings()->get('session_days'));
    }

    /**
     * The Host check for this request (SEC-M3). Built-in names (addresses,
     * localhost, this machine) never read settings; any other name reads
     * public_url through this request's SettingsService, or PUBLIC_URL when the
     * database cannot be read, as legacy request_configured_hosts() does.
     */
    public static function hostCheck(bool $getShared = true): \Apotheke\Core\Http\HostCheck
    {
        if ($getShared) {
            return static::getSharedInstance('hostCheck');
        }
        $list = new \Apotheke\Core\Http\HostAllowList((string)gethostname());

        return new \Apotheke\Core\Http\HostCheck($list, static function () use ($list): array {
            $config = static::apothekeConfig();
            try {
                $publicUrl = (string)static::settings()->get('public_url');
            } catch (\Throwable) {
                $publicUrl = $config->publicUrl();
            }

            return $list->configured($publicUrl, $config->allowedHosts());
        });
    }

    /**
     * Spark's command runner, with the Apotheke commands that need
     * collaborators built here (constructor injection, no service() in commands).
     */
    public static function commands(bool $getShared = true): \CodeIgniter\CLI\Commands
    {
        if ($getShared) {
            return static::getSharedInstance('commands');
        }

        return new \Apotheke\Core\Cli\WiredCommands(null, [
            \Apotheke\Settings\Commands\SettingsCommand::class => static fn (\Psr\Log\LoggerInterface $logger, \CodeIgniter\CLI\Commands $commands): \CodeIgniter\CLI\BaseCommand
                => new \Apotheke\Settings\Commands\SettingsCommand($logger, $commands, static fn (): \Apotheke\Settings\SettingsService => static::settings(false, new \Apotheke\Core\Security\CommandLine())),
        ]);
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
