<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

/**
 * The reads nearly every request makes, by the ReadCache key and tables their owners (Settings, Auth) remember them
 * under, so the D1 driver can read them in one batch at the start of a request (Services::apothekeDb()) and the
 * owners find them there. Each value has the shape its owner stores.
 */
final class RequestReads
{
    /** array<string,string>: settings name => JSON value (SettingsRepository::rows()) */
    public const SETTINGS = 'settings.rows';

    public const SETTINGS_SQL = 'SELECT name, value FROM settings';

    /** list<array{role_key:string,name:string,perms:string}>|null: the roles rows, built-in first; null when unreadable */
    public const ROLES = 'roles.rows';

    public const ROLES_SQL = 'SELECT role_key, name, perms FROM roles ORDER BY builtin DESC, id ASC';

    /** int: the number of accounts */
    public const USER_COUNT = 'users.count';

    public const USER_COUNT_SQL = 'SELECT COUNT(*) AS n FROM users';

    /** One account's row (UserRepository::find()). */
    public static function user(int $id): string
    {
        return 'users.row.' . $id;
    }

    /**
     * Read the settings, the roles and the account count in one batch on the D1 driver, with the applied migration
     * versions when $versions, and remember the first three in $cache. Null when the batch could not be read (a
     * table is missing before its migration): the callers read them on their own, as without this batch.
     *
     * @param \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $db
     * @return list<string>|null the applied versions ([] when not asked for); null also on another driver
     */
    public static function prefetch(\CodeIgniter\Database\BaseConnection $db, ReadCache $cache, bool $versions): ?array
    {
        $queries = [[self::SETTINGS_SQL, []], [self::ROLES_SQL, []], [self::USER_COUNT_SQL, []]];
        if ($versions) {
            $queries[] = ['SELECT version FROM schema_migrations', []];
        }
        $rows = Writes::readMany($db, $queries);
        if ($rows === null) {
            return null;
        }
        $settings = [];
        foreach ($rows[0] as $row) {
            $settings[(string)$row['name']] = (string)$row['value'];
        }
        $cache->put($db, self::SETTINGS, ['settings'], $settings);
        $cache->put($db, self::ROLES, ['roles'], $rows[1]);
        $cache->put($db, self::USER_COUNT, ['users'], (int)($rows[2][0]['n'] ?? 0));

        return $versions ? array_map(static fn (array $r): string => (string)$r['version'], $rows[3]) : [];
    }
}
