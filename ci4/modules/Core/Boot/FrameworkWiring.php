<?php

declare(strict_types=1);

namespace Apotheke\Core\Boot;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DatabaseFactory;
use Apotheke\Core\Config\DsnTranslator;
use Config\Database;

/**
 * Copies the translated DSN into CI4's default connection group: before
 * routing on the web (pre_system), and from Services::apothekeDb, because
 * spark does not fire pre_system. Idempotent.
 */
final class FrameworkWiring
{
    public static function apply(?ApothekeConfig $config = null): void
    {
        $config ??= ApothekeConfig::fromEnvironment();
        $group = (new DatabaseFactory($config, new DsnTranslator()))->group();
        $database = config(Database::class);
        $database->default = array_merge($database->default, $group);
        // Search path is set by DatabaseFactory after a quoted CREATE SCHEMA. CI4 would
        // otherwise SET the raw name (folded, and before the schema exists).
        $database->default['schema'] = '';
    }
}
