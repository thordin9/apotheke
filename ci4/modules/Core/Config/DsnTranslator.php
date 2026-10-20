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

        throw new DsnException($this->bad($dsn));
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
            throw new DsnException($this->bad($dsn));
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
            throw new DsnException($this->bad($dsn));
        }
        $params = $this->pairs($body, $dsn);
        $host = (string)($params['host'] ?? '');
        $port = (string)($params['port'] ?? '');
        $name = (string)($params['dbname'] ?? '');
        if ($host === '' || $name === '' || $port === '' || preg_match('/^\d+$/', $port) !== 1) {
            throw new DsnException($this->bad($dsn));
        }
        unset($params['host'], $params['port'], $params['dbname']);
        $user = array_key_exists('user', $params) ? (string)$params['user'] : (string)($db['user'] ?? '');
        $password = array_key_exists('password', $params) ? (string)$params['password'] : (string)($db['password'] ?? '');
        unset($params['user'], $params['password']);

        $parts = ['host=' . $host, 'port=' . $port, 'dbname=' . $name];
        if ($user !== '') {
            $parts[] = 'user=' . $user;
        }
        if ($password !== '') {
            $parts[] = "password='" . str_replace("'", "\\'", $password) . "'";
        }
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

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

    /** @return array<string,string> */
    private function pairs(string $body, string $dsn): array
    {
        $out = [];
        foreach (explode(';', $body) as $part) {
            if ($part === '') {
                continue;
            }
            $bits = explode('=', $part, 2);
            if (count($bits) !== 2 || $bits[0] === '') {
                throw new DsnException($this->bad($dsn));
            }
            $out[$bits[0]] = $bits[1];
        }
        if ($out === []) {
            throw new DsnException($this->bad($dsn));
        }

        return $out;
    }

    private function bad(string $dsn): string
    {
        $safe = preg_replace('/(password=)[^;]*/i', '$1***', $dsn) ?? $dsn;

        return 'Unsupported database DSN "' . $safe . '". Use sqlite:<path> or pgsql:host=…;port=…;dbname=….';
    }
}

final class DsnException extends RuntimeException
{
}
