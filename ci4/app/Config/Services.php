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
     * The request, without CI4's user-agent tables: nothing reads the parsed
     * browser, platform or robot, and building Config\UserAgents (four long
     * lists, each entry checked against the environment) and matching the
     * header against them was a fixed cost on every request (boot cost step).
     * getUserAgent() still returns the header as sent.
     */
    public static function incomingrequest(?\Config\App $config = null, bool $getShared = true): \CodeIgniter\HTTP\IncomingRequest
    {
        if ($getShared) {
            return static::getSharedInstance('request', $config);
        }
        $config ??= config(\Config\App::class);

        return new \CodeIgniter\HTTP\IncomingRequest(
            $config,
            static::get('uri'),
            'php://input',
            new \CodeIgniter\HTTP\UserAgent(new class extends \Config\UserAgents {
                public array $platforms = [];
                public array $browsers = [];
                public array $mobiles = [];
                public array $robots = [];
            }),
        );
    }

    /**
     * The CI4 connection: one database login per request. BootMigration checks
     * the schema on it once per process (not on every request in a worker) and
     * opens the migration runner's own connection only when a migration is
     * missing; apotheke:migrate is the explicit way.
     */
    public static function apothekeDb(bool $getShared = true): object
    {
        if ($getShared) {
            return static::getSharedInstance('apothekeDb');
        }
        $config = static::apothekeConfig();
        if (PHP_SAPI === 'cli') {
            // spark fires no pre_system; on the web Events.php has already wired the group.
            \Apotheke\Core\Boot\FrameworkWiring::apply($config);
        }
        $dbConfig = $config->database();
        $translator = new \Apotheke\Core\Config\DsnTranslator();
        if ($translator->credentialsDiffer($dbConfig)) {
            \Apotheke\Migrations\PdoMigrationRunner::warnCredentialsDiffer();
        }
        $factory = new \Apotheke\Core\Config\DatabaseFactory($config, $translator);
        try {
            $db = \Config\Database::connect();
            $factory->apply($db);
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            // CI4 connects lazily, on apply()'s first query. A refused connect carries
            // libpq's text, which names the user. Report it as the sanitized line, as
            // legacy db() does for either driver (a SQLite file that cannot be opened).
            if (!isset($db) || $db->connID !== false) {
                throw $e;
            }
            // The runner's file declares the exception too: load it before naming the class.
            $line = \Apotheke\Migrations\PdoMigrationRunner::connectFailure($dbConfig);
            throw new \Apotheke\Migrations\MigrateConnectException($line);
        }
        \Apotheke\Migrations\BootMigration::ensure($config, $db);

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
            \Apotheke\Auth\Commands\AccountsCommand::class => static fn (\Psr\Log\LoggerInterface $logger, \CodeIgniter\CLI\Commands $commands): \CodeIgniter\CLI\BaseCommand
                => new \Apotheke\Auth\Commands\AccountsCommand($logger, $commands, static fn (mixed $in, mixed $out, mixed $err): \Apotheke\Auth\Commands\AccountsCli => static::accountsCli($in, $out, $err)),
            \Apotheke\Backup\Commands\BackupCommand::class => static fn (\Psr\Log\LoggerInterface $logger, \CodeIgniter\CLI\Commands $commands): \CodeIgniter\CLI\BaseCommand
                => new \Apotheke\Backup\Commands\BackupCommand($logger, $commands, static fn (mixed $in, mixed $out, mixed $err): \Apotheke\Backup\Commands\BackupCli => static::backupCli($in, $out, $err)),
            \Apotheke\Backup\Commands\RestoreCommand::class => static fn (\Psr\Log\LoggerInterface $logger, \CodeIgniter\CLI\Commands $commands): \CodeIgniter\CLI\BaseCommand
                => new \Apotheke\Backup\Commands\RestoreCommand($logger, $commands, static fn (mixed $in, mixed $out, mixed $err): \Apotheke\Backup\Commands\BackupCli => static::backupCli($in, $out, $err)),
        ]);
    }

    /** Undo steps for this request's unfinished work (Core\Http\RequestCleanup). Per request, never persistent. */
    public static function requestCleanup(bool $getShared = true): \Apotheke\Core\Http\RequestCleanup
    {
        if ($getShared) {
            return static::getSharedInstance('requestCleanup');
        }

        return new \Apotheke\Core\Http\RequestCleanup();
    }

    /** Served photos and thumbnails: DATA_DIR/images and DATA_DIR/thumbs on local disk. */
    public static function servedImages(bool $getShared = true): \Apotheke\Media\Storage\ServedImageWriter
    {
        if ($getShared) {
            return static::getSharedInstance('servedImages');
        }

        return new \Apotheke\Media\Storage\ServedImageWriter(new \Apotheke\Core\Storage\LocalStorage(static::apothekeConfig()->dataDir()));
    }

    /** Photo uploads: originals under ORIGINALS_DIR (files 0640), the served copy through servedImages. */
    public static function imageStore(bool $getShared = true): \Apotheke\Media\ImageStore
    {
        if ($getShared) {
            return static::getSharedInstance('imageStore');
        }
        \Apotheke\Media\ImageLimits::applyImagickLimits();

        return new \Apotheke\Media\ImageStore(
            static::settings(),
            new \Apotheke\Media\Storage\OriginalStore(new \Apotheke\Core\Storage\LocalStorage(static::apothekeConfig()->originalsDir(), 0640)),
            static::servedImages(),
            new \Apotheke\Media\Storage\ImagickDecoder(),
            static::requestCleanup(),
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
     *
     * @param array{id:?int,username:string,role:string,status:string,guest:bool,anon:bool}|null $user
     */
    public static function layout(bool $getShared = true, ?array $user = null): \Apotheke\Layout\Page
    {
        if ($getShared && $user === null) {
            return static::getSharedInstance('layout');
        }
        $check = new \Apotheke\Auth\PermissionCheck(static::roles());
        $viewer = new \Apotheke\Layout\Viewer(
            // $user: the visitor as the request began (legacy current_user()'s per-request cache),
            // for a page drawn after the account changed (a stronger password hash).
            $user !== null ? static fn (): array => $user : static fn (): ?array => static::currentUser()->resolve(),
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
            static fn (): ?array => is_string($_GET['undo'] ?? null) && $_GET['undo'] !== '' ? static::undoTokens()->bar($_GET['undo']) : null,
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
        // Resolved when the handler runs, so it is the current request's list (exceptions is persistent in worker mode).
        $config->cleanup ??= static function (): void {
            static::requestCleanup()->run();
        };
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
            static::imageStore(),
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

    /** Accounts for the sign-in pages (Auth), with the failed sign-in deadline from SIGN_IN_FAIL_MS (sign_in_fail_ms). Stateless. */
    public static function accounts(bool $getShared = true): \Apotheke\Auth\Accounts
    {
        if ($getShared) {
            return static::getSharedInstance('accounts');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Auth\Accounts(
            new \Apotheke\Auth\UserRepository($db),
            static::roles(),
            new \Apotheke\Core\Libraries\Clock(),
            new \Apotheke\Auth\SignInPace((int)static::apothekeConfig()->values()['sign_in_fail_ms']),
        );
    }

    /**
     * Sign-in and registration throttling (Auth) for this request's client
     * address (legacy client_address(): X-Forwarded-For only from a trusted
     * proxy with trust_proxy on) at legacy app_time().
     */
    public static function rateLimiter(bool $getShared = true): \Apotheke\Auth\RateLimiter
    {
        if ($getShared) {
            return static::getSharedInstance('rateLimiter');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();
        try {
            $trust = (bool)static::settings()->get('trust_proxy');
        } catch (\Throwable) {
            $trust = false;
        }
        $address = (new \Apotheke\Core\Http\ClientAddress())->from(
            is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
            is_string($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '',
            $trust,
            static::apothekeConfig()->trustedProxies(),
        );

        return new \Apotheke\Auth\RateLimiter($db, $address, (new \Apotheke\Core\Libraries\Clock())->unix());
    }

    /** Sign out everywhere (Auth, SEC-N6). */
    public static function signOutEverywhere(bool $getShared = true): \Apotheke\Auth\SignOutEverywhere
    {
        if ($getShared) {
            return static::getSharedInstance('signOutEverywhere');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Auth\SignOutEverywhere(new \Apotheke\Auth\UserRepository($db));
    }

    /** The search index over items (Search): SQLite FTS5 or Postgres pg_trgm, rebuilt when the data changes. */
    public static function searchIndex(bool $getShared = true): \Apotheke\Search\SearchIndex
    {
        if ($getShared) {
            return static::getSharedInstance('searchIndex');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Search\SearchIndex($db, static::taxonomy(), (bool)static::settings()->get('search_typos'));
    }

    /** "Today", expiry and the statistics' buckets in the `timezone` setting (Catalog). */
    public static function calendar(bool $getShared = true): \Apotheke\Catalog\Calendar
    {
        if ($getShared) {
            return static::getSharedInstance('calendar');
        }
        $s = static::settings();

        return new \Apotheke\Catalog\Calendar(new \Apotheke\Core\Libraries\Clock(), (string)$s->get('timezone'), (int)$s->get('expiry_soon_days'));
    }

    /** The list and search (Catalog). */
    public static function itemList(bool $getShared = true): \Apotheke\Catalog\ItemList
    {
        if ($getShared) {
            return static::getSharedInstance('itemList');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Catalog\ItemList($db, static::searchIndex(), static::calendar());
    }

    /** The overview's statistics (Catalog). */
    public static function catalogStats(bool $getShared = true): \Apotheke\Catalog\Stats
    {
        if ($getShared) {
            return static::getSharedInstance('catalogStats');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Catalog\Stats($db, static::taxonomy(), static::calendar());
    }

    /** Saved filters (Catalog). */
    public static function savedFilters(bool $getShared = true): \Apotheke\Catalog\SavedFilters
    {
        if ($getShared) {
            return static::getSharedInstance('savedFilters');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Catalog\SavedFilters($db, new \Apotheke\Core\Libraries\Clock(), (int)static::settings()->get('saved_filters_max'));
    }

    /** Tags in use (Taxonomy). */
    public static function tags(bool $getShared = true): \Apotheke\Taxonomy\TagRepository
    {
        if ($getShared) {
            return static::getSharedInstance('tags');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Taxonomy\TagRepository($db, new \Apotheke\Core\Libraries\Clock());
    }

    /** Favourites and recently viewed (Favourites), for this request's device cookie. */
    public static function favourites(bool $getShared = true): \Apotheke\Favourites\Favourites
    {
        if ($getShared) {
            return static::getSharedInstance('favourites');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();
        $s = static::settings();

        return new \Apotheke\Favourites\Favourites($db, static::itemList(), (string)$s->get('recent_scope'), (int)$s->get('recent_limit'), $_COOKIE[\Apotheke\Favourites\Favourites::COOKIE] ?? null);
    }

    /** The list's "Recently viewed" row (Catalog's RecentItems, implemented by Favourites). */
    public static function recentItems(bool $getShared = true): \Apotheke\Catalog\RecentItems
    {
        return static::favourites($getShared);
    }

    /** One item, its photos and attachments (Catalog, PLAN 11a). */
    public static function itemRepository(bool $getShared = true): \Apotheke\Catalog\Items\ItemRepository
    {
        if ($getShared) {
            return static::getSharedInstance('itemRepository');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Catalog\Items\ItemRepository($db, new \Apotheke\Core\Libraries\Clock(), static::itemHistory(), static::taxonomy(), static::tags(),
            static::imageStore(), new \Apotheke\Core\Storage\LocalStorage(static::apothekeConfig()->dataDir()), static::photoRepository());
    }

    /** An item's photos: add, order, cover, delete, crop and rotate (Catalog, PLAN 11a part 2). */
    public static function photoRepository(bool $getShared = true): \Apotheke\Catalog\Items\PhotoRepository
    {
        if ($getShared) {
            return static::getSharedInstance('photoRepository');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Catalog\Items\PhotoRepository($db, new \Apotheke\Core\Libraries\Clock(), static::itemHistory(), static::imageStore(), static::undoTokens());
    }

    /** Undo tokens (Undo): deleted items' files are released through the item repository's deletes. */
    public static function undoTokens(bool $getShared = true): \Apotheke\Undo\UndoTokens
    {
        if ($getShared) {
            return static::getSharedInstance('undoTokens');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Undo\UndoTokens($db, new \Apotheke\Core\Libraries\Clock(), (int)static::settings()->get('undo_seconds'),
            static function (string $file): void {
                static::imageStore()->delete($file);
            },
            static function (string $file): void {
                static::itemRepository()->deleteAttachmentFile($file);
            });
    }

    /** Putting items back from an undo token (Catalog). */
    public static function undoApply(bool $getShared = true): \Apotheke\Catalog\Items\UndoApply
    {
        if ($getShared) {
            return static::getSharedInstance('undoApply');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Catalog\Items\UndoApply($db, static::undoTokens(), static::itemRepository(), static::taxonomy(), static::tags(), static::itemHistory());
    }

    /** The item history, read side (Activity). */
    public static function activityLog(bool $getShared = true): \Apotheke\Activity\ActivityLog
    {
        if ($getShared) {
            return static::getSharedInstance('activityLog');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Activity\ActivityLog($db);
    }

    /** The history as HTML (Activity). */
    public static function activityView(bool $getShared = true): \Apotheke\Activity\Views\ActivityView
    {
        if ($getShared) {
            return static::getSharedInstance('activityView');
        }
        $s = static::settings();

        return new \Apotheke\Activity\Views\ActivityView(static::layout(), (string)$s->get('currency'), (string)$s->get('time_format'), (string)$s->get('timezone'));
    }

    /** The item page and form (Catalog). */
    public static function itemView(bool $getShared = true): \Apotheke\Catalog\Views\ItemView
    {
        if ($getShared) {
            return static::getSharedInstance('itemView');
        }
        $s = static::settings();
        $keys = ['default_sort', 'list_view', 'currency', 'currency_position', 'money_format', 'app_name'];
        $list = new \Apotheke\Catalog\Views\ListView(static::layout(), static::calendar(), array_combine($keys, array_map(static fn (string $k): string => (string)$s->get($k), $keys)),
            \Apotheke\Core\Libraries\Version::current(), $s->signInEnabled());
        $due = $s->get('lend_due_days');

        return new \Apotheke\Catalog\Views\ItemView(static::layout(), $list, static::calendar(), static::activityView(), new \Apotheke\Notes\MarkdownRenderer(), [
            'currency' => (string)$s->get('currency'), 'date_format' => (string)$s->get('date_format'), 'max_photos' => (int)$s->get('max_photos'),
            'max_upload' => static::imageStore()->maxUpload(), 'undo_seconds' => (int)$s->get('undo_seconds'), 'attach_max_per_item' => (int)$s->get('attach_max_per_item'),
            'attach_types' => (string)$s->get('attach_types'), 'attach_max_mb' => (int)$s->get('attach_max_mb'), 'lend_due_days' => $due === null ? null : (int)$due,
        ]);
    }

    /** The item form's AI autofill attributes (Autofill, for the role's "create"). */
    public static function formAutofill(bool $getShared = true): \Apotheke\Catalog\Items\FormAutofill
    {
        if ($getShared) {
            return static::getSharedInstance('formAutofill');
        }

        return new \Apotheke\Catalog\Items\FormAutofill(static function (): ?array {
            $af = static::autofill();
            $check = new \Apotheke\Auth\PermissionCheck(static::roles());
            if (!$af->enabled() || !$check->allows(static::currentUser()->resolve(), 'create')) {
                return null;
            }
            $s = static::settings();

            return ['timeout' => (int)$s->get('autofill_timeout'), 'side' => $af->imageSide($af->knownInfo()), 'auto' => (bool)$s->get('autofill_auto'), 'latency' => $af->expectedMs()];
        });
    }

    /** Legacy require_perm() inside an action (Auth, for the signed-in role). */
    public static function permissionGate(bool $getShared = true): \Apotheke\Core\Security\PermissionGate
    {
        if ($getShared) {
            return static::getSharedInstance('permissionGate');
        }

        return new class () implements \Apotheke\Core\Security\PermissionGate {
            public function require(string $permission): void
            {
                $refusal = (new \Apotheke\Auth\PermissionCheck(Services::roles()))->refusal(Services::currentUser()->resolve(), $permission);
                if ($refusal !== null) {
                    throw new \Apotheke\Core\Exceptions\UserError($refusal === \Apotheke\Auth\PermissionCheck::SIGN_IN ? 'Not signed in' : $refusal, 403);
                }
            }
        };
    }

    /** The router matches legacy's exact request path (Core\Http\LegacyPathRouter). */
    public static function router(?\CodeIgniter\Router\RouteCollectionInterface $routes = null, ?\CodeIgniter\HTTP\Request $request = null, bool $getShared = true): \CodeIgniter\Router\Router
    {
        if ($getShared) {
            return static::getSharedInstance('router', $routes, $request);
        }
        $routes ??= static::routes();
        $request ??= static::request();

        return new \Apotheke\Core\Http\LegacyPathRouter($routes, $request);
    }

    /** Accounts on Control Panel → Users & sign-in (Auth): only an admin changes an admin account. */
    public static function userAdmin(bool $getShared = true): \Apotheke\Auth\UserAdmin
    {
        if ($getShared) {
            return static::getSharedInstance('userAdmin');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Auth\UserAdmin(new \Apotheke\Auth\UserRepository($db), static::roles(), static::accounts(),
            new \Apotheke\Auth\AdminGuard(static::currentUser()), new \Apotheke\Core\Libraries\Clock());
    }

    /** Roles and permissions on Control Panel → Users & sign-in (Auth). */
    public static function roleAdmin(bool $getShared = true): \Apotheke\Auth\RoleAdmin
    {
        if ($getShared) {
            return static::getSharedInstance('roleAdmin');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Auth\RoleAdmin(static::roles(), new \Apotheke\Auth\UserRepository($db), static::settings(), new \Apotheke\Core\Libraries\Clock());
    }

    /** Sign-in on or off (Auth): on needs an active admin, off needs an admin. */
    public static function signInSwitch(bool $getShared = true): \Apotheke\Auth\SignInSwitch
    {
        if ($getShared) {
            return static::getSharedInstance('signInSwitch');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Auth\SignInSwitch(static::settings(), new \Apotheke\Auth\UserRepository($db), new \Apotheke\Auth\AdminGuard(static::currentUser()));
    }

    /**
     * The account forms of bin/settings.php (Auth): the command line is the
     * actor, and it creates the first admin from APP_PASSWORD where legacy
     * auth_enabled() does.
     *
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public static function accountsCli(mixed $in, mixed $out, mixed $err): \Apotheke\Auth\Commands\AccountsCli
    {
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();
        $settings = static::settings(false, new \Apotheke\Core\Security\CommandLine());
        $users = new \Apotheke\Auth\UserRepository($db);
        $roles = new \Apotheke\Auth\RoleRepository($db);
        $clock = new \Apotheke\Core\Libraries\Clock();
        $accounts = new \Apotheke\Auth\Accounts($users, $roles, $clock);
        $password = static::apothekeConfig()->values()['app_password'] ?? '';
        $actor = new \Apotheke\Auth\Commands\CommandLineActor(static function () use ($accounts, $settings, $password): void {
            $accounts->ensureFirstAdmin((bool)$settings->get('auth_enabled'), is_string($password) ? $password : '');
        });
        $guard = new \Apotheke\Auth\AdminGuard($actor);

        return new \Apotheke\Auth\Commands\AccountsCli(
            new \Apotheke\Auth\UserAdmin($users, $roles, $accounts, $guard, $clock),
            $users,
            $roles,
            new \Apotheke\Auth\SignInSwitch($settings, $users, $guard),
            new \Apotheke\Auth\SignOutEverywhere($users),
            $settings,
            $actor,
            $in,
            $out,
            $err,
        );
    }

    /** An item's attachments (Catalog, PLAN 11b): files under DATA_DIR/files. */
    public static function attachments(bool $getShared = true): \Apotheke\Catalog\Items\Attachments
    {
        if ($getShared) {
            return static::getSharedInstance('attachments');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();
        $s = static::settings();

        return new \Apotheke\Catalog\Items\Attachments($db, new \Apotheke\Core\Libraries\Clock(), static::itemHistory(), static::itemRepository(),
            new \Apotheke\Core\Storage\LocalStorage(static::apothekeConfig()->dataDir(), null, 0770), (string)$s->get('attach_types'), (int)$s->get('attach_max_mb'), (int)$s->get('attach_max_per_item'));
    }

    /** The +/- buttons (BulkMerge, PLAN 11b). */
    public static function quantityAdjust(bool $getShared = true): \Apotheke\BulkMerge\QuantityAdjust
    {
        if ($getShared) {
            return static::getSharedInstance('quantityAdjust');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\BulkMerge\QuantityAdjust($db, new \Apotheke\Core\Libraries\Clock(), static::itemHistory());
    }

    /** Duplicate an item, with copies of its photos (BulkMerge). */
    public static function duplicate(bool $getShared = true): \Apotheke\BulkMerge\Duplicate
    {
        if ($getShared) {
            return static::getSharedInstance('duplicate');
        }

        return new \Apotheke\BulkMerge\Duplicate(static::itemRepository(), static::photoRepository(), static::imageStore());
    }

    /** Merging duplicates (BulkMerge). */
    public static function mergeService(bool $getShared = true): \Apotheke\BulkMerge\MergeService
    {
        if ($getShared) {
            return static::getSharedInstance('mergeService');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\BulkMerge\MergeService($db, new \Apotheke\Core\Libraries\Clock(), static::itemHistory(), static::itemRepository(), static::tags(),
            static::itemList(), (string)static::settings()->get('default_sort'));
    }

    /** The list's bulk actions (BulkMerge). */
    public static function bulkService(bool $getShared = true): \Apotheke\BulkMerge\BulkService
    {
        if ($getShared) {
            return static::getSharedInstance('bulkService');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\BulkMerge\BulkService($db, static::itemRepository(), static::taxonomy(), static::itemHistory(), static::imageStore());
    }

    /** The bulk delete and merge pages (BulkMerge). */
    public static function bulkMergeView(bool $getShared = true): \Apotheke\BulkMerge\Views\BulkMergeView
    {
        if ($getShared) {
            return static::getSharedInstance('bulkMergeView');
        }
        $s = static::settings();

        return new \Apotheke\BulkMerge\Views\BulkMergeView(static::layout(), (int)$s->get('undo_seconds'), (string)$s->get('currency'));
    }

    /** The QR labels page (Labels, PLAN 13), with legacy public_url()'s base for this request. */
    public static function labelsView(bool $getShared = true): \Apotheke\Labels\Views\LabelsView
    {
        if ($getShared) {
            return static::getSharedInstance('labelsView');
        }
        $s = static::settings();
        $cfg = [];
        foreach (['label_width_mm', 'label_height_mm', 'label_columns', 'label_gap_mm', 'label_border', 'label_show_sub', 'label_show_url'] as $k) {
            $cfg[$k] = (int)$s->get($k);
        }
        $publicUrl = (string)$s->get('public_url');
        $server = static::request()->getServer();
        $check = static::hostCheck(false);
        $base = (new \Apotheke\Core\Http\RequestScheme((bool)$s->get('trust_proxy')))
            ->publicBase(is_array($server) ? $server : [], static fn (string $host): bool => $check->passes($host), $publicUrl);

        return new \Apotheke\Labels\Views\LabelsView(static::layout(), $cfg, $publicUrl, $base);
    }

    /** The barcode lookup (Catalog, PLAN 13). */
    public static function barcode(bool $getShared = true): \Apotheke\Catalog\Barcode
    {
        if ($getShared) {
            return static::getSharedInstance('barcode');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Catalog\Barcode($db);
    }

    /** Lending: lend, return and the items that are out (Lending, PLAN 13). */
    public static function loans(bool $getShared = true): \Apotheke\Lending\Loans
    {
        if ($getShared) {
            return static::getSharedInstance('loans');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Lending\Loans($db, new \Apotheke\Core\Libraries\Clock(), static::calendar(), static::itemHistory(), static::itemRepository());
    }

    /** The "Lent out" page (Lending). */
    public static function lentView(bool $getShared = true): \Apotheke\Lending\Views\LentView
    {
        if ($getShared) {
            return static::getSharedInstance('lentView');
        }

        return new \Apotheke\Lending\Views\LentView(static::layout(), static::calendar(), static::itemView());
    }

    /** Stocktakes: start, tick, finish and cancel (Stocktake, PLAN 13). */
    public static function stocktakes(bool $getShared = true): \Apotheke\Stocktake\Stocktakes
    {
        if ($getShared) {
            return static::getSharedInstance('stocktakes');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\Stocktake\Stocktakes($db, new \Apotheke\Core\Libraries\Clock(), static::taxonomy(), static::itemRepository(), static::itemHistory(), static::barcode());
    }

    /** The stocktake pages (Stocktake). */
    public static function stocktakeView(bool $getShared = true): \Apotheke\Stocktake\Views\StocktakeView
    {
        if ($getShared) {
            return static::getSharedInstance('stocktakeView');
        }

        return new \Apotheke\Stocktake\Views\StocktakeView(static::layout(), static::stocktakes(), static::itemRepository(), static::activityView(), (bool)static::settings()->get('stocktake_apply_moves'));
    }

    /** CSV import: upload, mapping, dry run and import (ImportExport, PLAN 14). */
    public static function imports(bool $getShared = true): \Apotheke\ImportExport\Imports
    {
        if ($getShared) {
            return static::getSharedInstance('imports');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\ImportExport\Imports($db, new \Apotheke\Core\Libraries\Clock(), static::calendar(), new \Apotheke\Core\Storage\LocalStorage(static::apothekeConfig()->dataDir()),
            static::itemRepository(), static::taxonomy(), static::loans(), static function (): ?string {
                if (PHP_SAPI === 'cli') {
                    return null;
                }
                $user = static::currentUser()->resolve();

                return $user !== null && !$user['guest'] ? (string)$user['username'] : null;
            }, (bool)static::settings()->get('import_create_missing'));
    }

    /** The CSV import screens (ImportExport). */
    public static function importView(bool $getShared = true): \Apotheke\ImportExport\Views\ImportView
    {
        if ($getShared) {
            return static::getSharedInstance('importView');
        }

        return new \Apotheke\ImportExport\Views\ImportView(static::layout(), static::activityView());
    }

    /** The CSV export (ImportExport). */
    public static function csvExport(bool $getShared = true): \Apotheke\ImportExport\CsvExport
    {
        if ($getShared) {
            return static::getSharedInstance('csvExport');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();

        return new \Apotheke\ImportExport\CsvExport($db);
    }

    /** Backup zips (Backup, PLAN 14 part 2): the dump and the files, read from DATA_DIR and ORIGINALS_DIR. */
    public static function backups(bool $getShared = true, ?\Apotheke\Settings\SettingsService $settings = null): \Apotheke\Backup\Backups
    {
        if ($getShared && $settings === null) {
            return static::getSharedInstance('backups');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();
        $config = static::apothekeConfig();

        return new \Apotheke\Backup\Backups($db, new \Apotheke\Core\Libraries\Clock(), $settings ?? static::settings(), $config->dataDir(), $config->originalsDir());
    }

    /**
     * Checking and restoring a backup (Backup). $settings: the CLI passes
     * its own (the command line may restore the sign-in settings).
     */
    public static function restorer(bool $getShared = true, ?\Apotheke\Settings\SettingsService $settings = null): \Apotheke\Backup\Restorer
    {
        if ($getShared && $settings === null) {
            return static::getSharedInstance('restorer');
        }
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db */
        $db = static::apothekeDb();
        $config = static::apothekeConfig();
        $settings ??= static::settings();

        return new \Apotheke\Backup\Restorer($db, static::backups(false, $settings), $settings, static::undoTokens(), static function (): array {
            return array_keys((new \Apotheke\Migrations\PdoMigrationRunner(\Apotheke\Migrations\PdoMigrationRunner::repoRoot() . '/migrations'))->available());
        }, new \Apotheke\Core\Storage\LocalStorage($config->dataDir()), new \Apotheke\Core\Storage\LocalStorage($config->originalsDir(), 0640), (int)$settings->get('image_thumb_max'), static::requestCleanup());
    }

    /** The Backup & restore page (Backup). */
    public static function backupView(bool $getShared = true): \Apotheke\Backup\Views\BackupView
    {
        if ($getShared) {
            return static::getSharedInstance('backupView');
        }

        return new \Apotheke\Backup\Views\BackupView(static::layout(), static::activityView());
    }

    /**
     * bin/backup.php and bin/restore.php (Backup), as the command line: it
     * may restore the sign-in settings.
     *
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public static function backupCli(mixed $in, mixed $out, mixed $err): \Apotheke\Backup\Commands\BackupCli
    {
        $settings = static::settings(false, new \Apotheke\Core\Security\CommandLine());

        return new \Apotheke\Backup\Commands\BackupCli(static::backups(false, $settings), static::restorer(false, $settings), static::apothekeConfig()->dataDir(), $in, $out, $err, (string)(getenv('APOTHEKE_CLI_CWD') ?: ''));
    }
}
