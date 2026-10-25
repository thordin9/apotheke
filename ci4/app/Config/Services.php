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
        $config = static::apothekeConfig()->values();

        return new \Apotheke\Settings\SettingsService(
            new \Apotheke\Settings\SettingsRepository($db),
            new \Apotheke\Settings\SettingsSchema(),
            static::brandStore(),
            new \Apotheke\Core\Libraries\Clock(),
            $config,
            null,
            $actor ?? static::currentUser(),
            // S-L5: APP_KEY / APP_KEY_FILE, resolved on first use and once per request.
            \Apotheke\Core\Security\SecretBox::fromEnvironment(getenv(), \Apotheke\Settings\SettingsService::backupRoots($config)),
        );
    }

    /**
     * CI4's session service is the native apothekesid session (#76 L-1): it
     * never starts a second, handler-backed session beside the one that
     * SessionCookie loads and SessionToken starts on demand, so CI4's timed
     * id change can never end it. The config argument is ignored.
     *
     * @param \Config\Session|null $config
     */
    public static function session(?\Config\Session $config = null, bool $getShared = true): \CodeIgniter\Session\Session
    {
        if ($getShared) {
            return static::getSharedInstance('session', $config);
        }

        return new \Apotheke\Core\Http\NativeSession();
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

    /** The roles table (Auth). Stateless: every call reads the table. */
    public static function roles(bool $getShared = true): \Apotheke\Auth\RoleRepository
    {
        if ($getShared) {
            return static::getSharedInstance('roles');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Auth\RoleRepository($db);
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
     * Idle limit from session_idle_hours (legacy session_idle_seconds() on
     * main). It keeps no value: each seconds() call reads the stored row
     * through this request's SettingsService. SessionCookie asks for a new
     * instance (false) every time.
     */
    public static function sessionIdle(bool $getShared = true): \Apotheke\Core\Http\SessionIdle
    {
        if ($getShared) {
            return static::getSharedInstance('sessionIdle');
        }

        return new \Apotheke\Core\Http\SessionIdle(static fn (): mixed => static::settings()->stored('session_idle_hours'));
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
     * Theme, labels, manifest and favicon output. Bundled icons are the
     * Layout module's copy under ci4/public/assets.
     */
    public static function branding(bool $getShared = true): \Apotheke\Settings\Branding\Branding
    {
        if ($getShared) {
            return static::getSharedInstance('branding');
        }

        return new \Apotheke\Settings\Branding\Branding(
            static::settings(),
            static::brandStore(),
            static::assetUrl(),
        );
    }

    /** Versioned URLs of the bundled assets under ci4/public/assets (a copy of legacy public/assets). */
    public static function assetUrl(bool $getShared = true): \Apotheke\Core\Libraries\AssetUrl
    {
        if ($getShared) {
            return static::getSharedInstance('assetUrl');
        }

        return new \Apotheke\Core\Libraries\AssetUrl(\Apotheke\Core\Config\ApothekeConfig::repoRoot() . '/ci4/public/assets');
    }

    /**
     * The shared page shell for this request (Layout). The user and role
     * checks come from Auth; the theme cookie and the way back from this
     * request. Nothing is read until a page is drawn.
     */
    public static function layout(bool $getShared = true): \Apotheke\Layout\Page
    {
        if ($getShared) {
            return static::getSharedInstance('layout');
        }
        $check = new \Apotheke\Auth\PermissionCheck(static::roles());
        $viewer = new \Apotheke\Layout\Viewer(
            static fn (): ?array => static::currentUser()->resolve(),
            static fn (?array $user, string $permission): bool => $check->allows($user, $permission),
            static fn (string $role): string => static::roles()->name($role),
            array_keys(\Apotheke\Auth\Permissions::ALL),
        );

        return new \Apotheke\Layout\Page(
            static::settings(),
            static::branding(),
            static::assetUrl(),
            $viewer,
            static fn (bool $create): string => (new \Apotheke\Core\Http\SessionToken(time()))->get($create),
            static fn (): bool => static::settings()->signInEnabled(),
            $_COOKIE['theme'] ?? null,
            is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/',
        );
    }

    /** Legacy view_error() for this request: the Layout page, or the plain page when it cannot be drawn. */
    public static function errorPage(bool $getShared = true): \Apotheke\Core\Http\ErrorPage
    {
        if ($getShared) {
            return static::getSharedInstance('errorPage');
        }

        return new \Apotheke\Layout\ErrorPage(static fn (): \Apotheke\Layout\Page => static::layout());
    }

    /**
     * CI4's exception handling, with the app's error page for web requests
     * (Config\Exceptions::$page, read when an error is answered).
     */
    public static function exceptions(?\Config\Exceptions $config = null, bool $getShared = true): \CodeIgniter\Debug\Exceptions
    {
        if ($getShared) {
            return static::getSharedInstance('exceptions', $config);
        }
        $config ??= config(\Config\Exceptions::class);
        if (PHP_SAPI !== 'cli') {
            $config->page ??= static fn (): \Apotheke\Core\Http\ErrorPage => static::errorPage();
        }

        return new \CodeIgniter\Debug\Exceptions($config);
    }

    /**
     * Autofill client for this request. Settings and the token come from
     * SettingsService. The warn-once file and temporary analysis JPEGs go
     * through Storage (local disk under DATA_DIR). The shared instance is
     * per request; the warn-once pair is the file, not this object.
     */
    public static function autofill(bool $getShared = true): \Apotheke\Autofill\AutofillService
    {
        if ($getShared) {
            return static::getSharedInstance('autofill');
        }
        $settings = static::settings();
        $config = static::apothekeConfig();
        $now = static fn (): int => time();
        $log = new \Apotheke\Autofill\LogRedactor(
            static function (string $message): void {
                error_log($message);
            },
            static fn (): string => $settings->autofillToken(),
        );
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Autofill\AutofillService(
            $settings,
            new \Apotheke\Core\Storage\LocalStorage($config->dataDir()),
            new \Apotheke\Autofill\AutofillClient($now),
            $log,
            $db,
            $config->dataDir() . '/images',
            $config->originalsDir(),
            \Apotheke\Core\Config\ApothekeConfig::repoRoot() . '/public/assets/autofill-sample.jpg',
            static function (int $seconds): void {
                sleep($seconds);
            },
            $now,
        );
    }

    /**
     * The item history writer (Activity). The actor is the signed-in account,
     * or none for the command line, sign-in off and the guest (legacy
     * current_actor()).
     */
    public static function itemHistory(bool $getShared = true): \Apotheke\Core\Libraries\ItemHistory
    {
        if ($getShared) {
            return static::getSharedInstance('itemHistory');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Activity\ActivityLogger($db, new \Apotheke\Core\Libraries\Clock(), static function (): ?string {
            if (PHP_SAPI === 'cli') {
                return null;
            }
            $user = static::currentUser()->resolve();

            return $user !== null && !$user['guest'] ? (string)$user['username'] : null;
        });
    }

    /** Locations and categories (Taxonomy). Stateless: every call reads the tables. */
    public static function taxonomy(bool $getShared = true): \Apotheke\Taxonomy\TaxonomyRepository
    {
        if ($getShared) {
            return static::getSharedInstance('taxonomy');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Taxonomy\TaxonomyRepository($db, new \Apotheke\Core\Libraries\Clock(), static::itemHistory());
    }
}
