<?php

namespace Config;

use CodeIgniter\Modules\Modules as BaseModules;

/**
 * Modules Configuration.
 *
 * NOTE: This class is required prior to Autoloader instantiation,
 *       and does not extend BaseConfig.
 */
class Modules extends BaseModules
{
    /**
     * --------------------------------------------------------------------------
     * Enable Auto-Discovery?
     * --------------------------------------------------------------------------
     *
     * If true, then auto-discovery will happen across all elements listed in
     * $aliases below. If false, no auto-discovery will happen at all,
     * giving a slight performance boost.
     *
     * @var bool
     */
    public $enabled = true;

    /**
     * --------------------------------------------------------------------------
     * Enable Auto-Discovery Within Composer Packages?
     * --------------------------------------------------------------------------
     *
     * If true, then auto-discovery will happen across all namespaces loaded
     * by Composer, as well as the namespaces configured locally.
     *
     * @var bool
     */
    public $discoverInComposer = false;

    /**
     * The Composer package list for Auto-Discovery
     * This setting is optional.
     *
     * E.g.:
     *   [
     *       'only' => [
     *           // List up all packages to auto-discover
     *           'codeigniter4/shield',
     *       ],
     *   ]
     *   or
     *   [
     *       'exclude' => [
     *           // List up packages to exclude.
     *           'pestphp/pest',
     *       ],
     *   ]
     *
     * @var array{only?: list<string>, exclude?: list<string>}
     */
    public $composerPackages = [];

    /**
     * --------------------------------------------------------------------------
     * Auto-Discovery Rules
     * --------------------------------------------------------------------------
     *
     * Aliases list of all discovery classes that will be active and used during
     * the current application request.
     *
     * If it is not listed, only the base application elements will be used.
     *
     * @var list<string>
     */
    public $aliases = [
        // Only services: CI4 finds Config\Services, which overrides the framework's, this way. No module
        // ships its own Config/Routes.php, Filters.php, Events.php or Registrar.php (the app's own files
        // load without discovery), so searching every namespace for them on each request is left out
        // (boot cost step). A module adds its routes, filters and events in app/Config.
        'services',
    ];
}
