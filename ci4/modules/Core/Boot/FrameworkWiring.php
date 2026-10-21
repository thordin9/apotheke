<?php

declare(strict_types=1);

namespace Apotheke\Core\Boot;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DatabaseFactory;
use Apotheke\Core\Config\DsnTranslator;
use Config\Database;

/** Copies the translated DSN into CI4's default connection group before routing. */
final class FrameworkWiring
{
    public static function apply(): void
    {
        $config = ApothekeConfig::fromEnvironment();
        $group = (new DatabaseFactory($config, new DsnTranslator()))->group();
        $database = config(Database::class);
        $database->default = array_merge($database->default, $group);
        // Search path is set by DatabaseFactory after a quoted CREATE SCHEMA. CI4 would
        // otherwise SET the raw name (folded, and before the schema exists).
        $database->default['schema'] = '';
    }
}
