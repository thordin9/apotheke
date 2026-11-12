<?php

declare(strict_types=1);

namespace Apotheke\Auth\Commands;

use Apotheke\Auth\Accounts;
use Apotheke\Core\Database\D1Executor;
use Apotheke\Core\Database\D1Pdo;
use Closure;
use PDO;
use PDOException;
use RuntimeException;

/**
 * `auth status` of legacy bin/settings.php, for the Docker entrypoint: whether
 * sign-in will be on, read without migrating or seeding. Exit 0 = on, 3 = off,
 * 4 = APP_PASSWORD too short (the message on stderr, never the value), 1 = no
 * database connection. A missing SQLite file stays missing; connecting may
 * still create an empty DB_SCHEMA or switch an older SQLite file to WAL, as
 * legacy does. With db.driver d1 (storage mode) the same answer is read over
 * the D1 bridge (d1()).
 */
final class AuthStatus
{
    /**
     * @param Closure():?PDO $connect a connection that runs no migration, null when the SQLite file does
     *        not exist yet; throws a RuntimeException with the sanitized connect line
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly Closure $connect,
        #[\SensitiveParameter] private readonly string $appPassword,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
    ) {
    }

    public function run(): int
    {
        try {
            $pdo = ($this->connect)();
        } catch (RuntimeException $e) {
            fwrite($this->stderr, (str_starts_with($e->getMessage(), 'Could not connect to the database') ? $e->getMessage() : 'Could not open the database.') . "\n");

            return 1;
        }
        $on = self::onAtStart($pdo, $this->appPassword);
        // APP_PASSWORD decides only on a new install; there it must meet PASSWORD_MIN (hardening, #75 QA L-2)
        if ($on !== self::onAtStart($pdo, '') && ($problem = Accounts::appPasswordProblem($this->appPassword)) !== null) {
            fwrite($this->stderr, $problem . "\n");

            return 4;
        }
        fwrite($this->stdout, $on ? "on\n" : "off\n");

        return $on ? 0 : 3;
    }

    /**
     * The connection for db.driver d1: the Installation's database over the D1 bridge. A probe query must
     * answer first, else it is the "no database" exit with $failure (the sanitized connect line), as a
     * refused PDO connection. The probe also asks whether the settings table exists: before the first
     * migration it does not, which is a new install (null, as a missing SQLite file), so no query fails on
     * the bridge at boot. The settings are then read through the PDO-shaped D1 adapter, so onAtStart()
     * gives the same answer as on SQLite, and nothing is migrated.
     *
     * @return Closure():?PDO
     */
    public static function d1(D1Executor $d1, string $failure): Closure
    {
        return static function () use ($d1, $failure): ?PDO {
            try {
                $rows = $d1->query("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = 'settings'");
            } catch (RuntimeException) {
                throw new RuntimeException($failure);
            }

            return (int)($rows[0]['n'] ?? 0) > 0 ? new D1Pdo($d1) : null;
        };
    }

    /**
     * Legacy auth_on_at_start(): the stored auth_enabled setting, else whether
     * APP_PASSWORD is set (the first migration seeds the setting from it). An
     * empty account list counts only with APP_PASSWORD, which then creates the
     * account admin. $pdo is null when there is no database yet.
     */
    public static function onAtStart(?PDO $pdo, #[\SensitiveParameter] string $appPassword): bool
    {
        $pw = $appPassword !== '';
        if ($pdo === null) {
            return $pw;
        }
        try {
            $st = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
            $st->execute(['auth_enabled']);
            $raw = $st->fetchColumn();
        } catch (PDOException) {
            return $pw;   // no settings table yet
        }
        if ($raw === false) {
            return $pw;
        }
        if (json_decode((string)$raw, true) !== true) {
            return false;
        }
        try {
            $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        } catch (PDOException) {
            $users = 0;
        }

        return $users > 0 || $pw;
    }
}
