<?php

declare(strict_types=1);

/**
 * The database half of `gen-api-fixture.sh` (plan §5 S1.4): the starting state each captured answer
 * is asked from, in the database a real v3 install would have.
 *
 *     php api-fixture-state.php settings                 # {name: value} for the rows the fixture pins
 *     php api-fixture-state.php set <name> <value>       # one settings row, as SettingsService stores it
 *     php api-fixture-state.php user <name> <role> <status>   # an account whose password is APP_PASSWORD
 *     php api-fixture-state.php pacing                   # SignInPace's constants and clamped deadlines
 *
 * Why this is a separate file rather than a page request: v3 changes these through forms on
 * `/settings/security`, which would make the capture depend on a *second* surface's behaviour. The
 * fixture is about the four API routes; its state is set the way an operator's would leave it, and
 * the value is written in the format `SettingsService` writes (a JSON scalar as text).
 *
 * The account's password is `APP_PASSWORD` from the environment, so the captured 200 answers are
 * about an account the test can recreate with the same password.
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$dataDir = (string) getenv('DATA_DIR');
if ($dataDir === '') {
    fwrite(STDERR, "DATA_DIR is not set\n");
    exit(2);
}

/** The connection v3 itself opens for `DB_DRIVER=sqlite`: the SQLite3 extension, not PDO. */
$open = static function () use ($dataDir): SQLite3 {
    $db = new SQLite3($dataDir . '/catalog.sqlite');
    $db->busyTimeout(5000);

    return $db;
};

$verb = $argv[1] ?? '';
switch ($verb) {
    case 'settings':
        $db = $open();
        $out = [];
        $result = $db->query("SELECT name, value FROM settings ORDER BY name");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $out[(string) $row['name']] = (string) $row['value'];
        }
        echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";
        break;

    case 'set':
        $name = (string) ($argv[2] ?? '');
        $value = (string) ($argv[3] ?? '');
        $db = $open();
        $statement = $db->prepare('UPDATE settings SET value = :value WHERE name = :name');
        $statement->bindValue(':name', $name);
        $statement->bindValue(':value', $value);
        $statement->execute();
        $changed = $db->changes();
        echo $name, '=', $value, ' rows=', $changed, "\n";
        break;

    case 'user':
        $name = (string) ($argv[2] ?? '');
        $role = (string) ($argv[3] ?? 'viewer');
        $status = (string) ($argv[4] ?? 'active');
        $password = (string) getenv('APP_PASSWORD');
        if ($password === '') {
            fwrite(STDERR, "APP_PASSWORD is not set\n");
            exit(2);
        }
        $db = $open();
        $statement = $db->prepare(
            'INSERT INTO users (username, username_key, password_hash, role, created_at, status, session_epoch)
             VALUES (:username, :key, :hash, :role, :created, :status, :epoch)'
        );
        $statement->bindValue(':username', $name);
        $statement->bindValue(':key', \Apotheke\Auth\UserRepository::key($name));
        $statement->bindValue(':hash', password_hash($password, PASSWORD_DEFAULT));
        $statement->bindValue(':role', $role);
        $statement->bindValue(':created', gmdate('Y-m-d H:i:s'));
        $statement->bindValue(':status', $status);
        $statement->bindValue(':epoch', '');
        $statement->execute();
        echo 'id=', $db->lastInsertRowID(), "\n";
        break;

    case 'pacing':
        $cases = [];
        foreach ([0, 1, 399, 400, 1000, 5000, 10_000, 10_001] as $configured) {
            $cases[(string) $configured] = (new \Apotheke\Auth\SignInPace($configured))->deadlineMs;
        }
        echo json_encode([
            'DEFAULT_MS' => \Apotheke\Auth\SignInPace::DEFAULT_MS,
            'MIN_MS' => \Apotheke\Auth\SignInPace::MIN_MS,
            'MAX_MS' => \Apotheke\Auth\SignInPace::MAX_MS,
            'clamped' => $cases,
        ], JSON_UNESCAPED_SLASHES), "\n";
        break;

    default:
        fwrite(STDERR, "usage: api-fixture-state.php settings|set|user|pacing\n");
        exit(2);
}
