<?php

declare(strict_types=1);

namespace Apotheke\Core\Config;

use RuntimeException;

/** Turns a PDO DSN into the CodeIgniter driver settings. A bad DSN fails at boot. */
final class DsnTranslator
{
    /**
     * @param array<string,mixed> $db
     * @return array<string,mixed>
     */
    public function translate(array $db): array
    {
        return $this->fromPdoDsn($this->pdoDsn($db), $db);
    }

    /**
     * @param array<string,mixed> $db
     * @return array<string,mixed>
     */
    public function fromPdoDsn(string $dsn, array $db): array
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            return $this->sqlite($dsn);
        }
        if (str_starts_with($dsn, 'pgsql:')) {
            return $this->pgsql($dsn, $db);
        }

        $this->bad($dsn);
    }

    /** @param array<string,mixed> $db */
    public function pdoDsn(array $db): string
    {
        if (($db['driver'] ?? '') === 'sqlite') {
            return 'sqlite:' . (string)($db['sqlite_path'] ?? '');
        }
        if ((string)($db['dsn'] ?? '') !== '') {
            return (string)$db['dsn'];
        }

        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            (string)($db['host'] ?? ''),
            (int)($db['port'] ?? 0),
            (string)($db['name'] ?? '')
        );
    }

    /** @return array<string,mixed> */
    private function sqlite(string $dsn): array
    {
        $path = substr($dsn, 7);
        if ($path === '') {
            $this->bad($dsn);
        }

        return [
            'DSN' => '',
            'DBDriver' => 'SQLite3',
            'database' => $path,
            'DBDebug' => false,
            'foreignKeys' => true,
            'busyTimeout' => 5000,
            'synchronous' => 1,
        ];
    }

    /**
     * @param array<string,mixed> $db
     * @return array<string,mixed>
     */
    private function pgsql(string $dsn, array $db): array
    {
        $body = substr($dsn, 6);
        if ($body === '') {
            $this->bad($dsn);
        }
        $params = $this->pairs($body, $dsn);
        $host = (string)($params['host'] ?? '');
        $port = (string)($params['port'] ?? '');
        $name = (string)($params['dbname'] ?? '');
        if ($host === '' || $name === '' || $port === '' || preg_match('/^\d+$/', $port) !== 1) {
            $this->bad($dsn);
        }
        unset($params['host'], $params['port'], $params['dbname']);
        [$user, $password] = self::credentials($params, $db);
        unset($params['user'], $params['password']);

        $parts = [self::libpq('host', $host), self::libpq('port', $port), self::libpq('dbname', $name)];
        foreach ($params as $key => $value) {
            $parts[] = self::libpq($key, $value);
        }
        // Last, as legacy PDO on PHP 8.3 appends them: libpq takes the last duplicate.
        $parts[] = self::libpq('user', $user);
        $parts[] = self::libpq('password', $password);

        $group = [
            'DSN' => implode(' ', $parts),
            'hostname' => $host,
            'port' => (int)$port,
            'database' => $name,
            'username' => $user,
            'password' => $password,
            'DBDriver' => 'Postgre',
            'DBDebug' => false,
            'charset' => 'utf8',
            'schema' => (string)($db['schema'] ?? ''),
        ];
        foreach (['connect_timeout', 'options', 'sslmode', 'service'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $group[$key] = $params[$key];
            }
        }

        return $group;
    }

    /**
     * The one credential rule for every CI4 connection, on any PHP version.
     * Legacy db_connect() hands DB_USER and DB_PASSWORD to PDO, and on PHP 8.3
     * (the shipped image) PDO always appends them after the DSN, so they win
     * over a user or password in DB_DSN, even an empty DB_PASSWORD and the
     * default DB_USER. CI4 applies that itself.
     *
     * @param array<string,string> $keywords the DSN's keywords, as libpq reads them
     * @param array<string,mixed> $db
     * @return array{0:string,1:string,2:bool} user, password, and whether DB_DSN set one that differs
     */
    public static function credentials(array $keywords, array $db): array
    {
        $user = (string)($db['user'] ?? '');
        $password = (string)($db['password'] ?? '');
        $differs = (array_key_exists('user', $keywords) && $keywords['user'] !== $user)
            || (array_key_exists('password', $keywords) && $keywords['password'] !== $password);

        return [$user, $password, $differs];
    }

    /**
     * What PdoMigrationRunner hands to PDO: the DSN without user or password,
     * plus the resolved user and password as PDO arguments. With no user or
     * password left in the DSN, PDO appends both on PHP 8.3 and 8.4 alike.
     * A DSN that does not tokenise is returned as it is, for PDO to reject.
     *
     * @param array<string,mixed> $db
     * @return array{0:string,1:string,2:string,3:bool} DSN, user, password, whether DB_DSN set one that differs
     */
    public static function forPdo(string $dsn, array $db): array
    {
        require_once dirname(__DIR__, 4) . '/src/config.php';
        $keywords = str_starts_with($dsn, 'pgsql:') ? config_dsn_keywords(str_replace(';', ' ', substr($dsn, 6))) : null;
        [$user, $password, $differs] = self::credentials($keywords ?? [], $db);
        if ($keywords !== null) {
            $parts = [];
            foreach ($keywords as $key => $value) {
                if ($key !== 'user' && $key !== 'password') {
                    $parts[] = self::libpq($key, $value);
                }
            }
            $dsn = 'pgsql:' . implode(' ', $parts);
        }

        return [$dsn, $user, $password, $differs];
    }

    /** libpq keyword/value: quote every value, escaping \ first and then '. */
    private static function libpq(string $key, string $value): string
    {
        $value = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        return $key . "='" . $value . "'";
    }

    /**
     * Keywords the way PDO pgsql and libpq read them: PDO turns every ";" into a
     * space, then libpq ignores whitespace around a key and "=", ends an unquoted
     * value at whitespace, and unquotes and unescapes a quoted one. The parser is
     * the legacy config_dsn_keywords(), so the log line and the connect string
     * read the same values. Keys come back lower-cased.
     *
     * @return array<string,string>
     */
    private function pairs(string $body, string $dsn): array
    {
        require_once dirname(__DIR__, 4) . '/src/config.php';
        $out = config_dsn_keywords(str_replace(';', ' ', $body));
        if ($out === null || $out === []) {
            $this->bad($dsn);
        }

        return $out;
    }

    /** @return never */
    private function bad(string $dsn): void
    {
        throw new DsnException($dsn);
    }

    /**
     * Error-log line. Only driver, host, port and dbname are copied out of the DSN.
     * A value that does not parse contributes nothing: the line is not a redaction of the raw string.
     */
    public static function logLine(string $driver, string $dsn): string
    {
        require_once dirname(__DIR__, 4) . '/src/config.php';
        $parts = ['Database ' . $driver];
        if (str_starts_with($dsn, 'sqlite:')) {
            return implode(' ', $parts);
        }
        $fields = config_dsn_public_fields($dsn);
        if (is_array($fields)) {
            foreach (['host', 'port', 'dbname'] as $key) {
                if (($fields[$key] ?? '') !== '') {
                    $parts[] = $key . '=' . $fields[$key];
                }
            }
        }

        return implode(' ', $parts);
    }
}

final class DsnException extends RuntimeException
{
    public const PUBLIC_MESSAGE = 'The database settings could not be used.';

    public function __construct(public readonly string $dsn)
    {
        parent::__construct(self::PUBLIC_MESSAGE);
    }
}
