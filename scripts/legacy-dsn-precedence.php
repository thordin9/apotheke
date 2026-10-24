<?php

declare(strict_types=1);

/**
 * Golden check of legacy db_connect() credential precedence, the rule CI4
 * copies (docs/configuration.md, db.password). Two throwaway roles log in
 * through the real src/config.php + src/db.php path, one process per case,
 * and current_user is compared with the expected row for this PHP version:
 *
 *   case                                   PHP 8.3 (shipped)  PHP 8.4
 *   a  DSN user/password only               fails              dsn role
 *   b  DB_USER/DB_PASSWORD only             env role           env role
 *   c  both set, differing                  env role           dsn role
 *   d  both set, DB_PASSWORD empty          fails              dsn role
 *   e  user= in application_name            env role           fails
 *   e2 password= in options                 env role           fails
 *   e3 c, plus user= in options             env role           dsn role
 *
 * e–e3: PHP 8.4's PDO drops DB_USER/DB_PASSWORD when user= or password=
 * appears anywhere in the DSN; with no DSN credentials (e, e2) it fails.
 *
 * Environment: TEST_PG_HOST, TEST_PG_PORT, TEST_PG_DB, TEST_PG_USER and
 * TEST_PG_PASSWORD (a role that may CREATE ROLE); APP_ROOT (default: this
 * repository). Passwords are random and never printed. Exit 0 on a match.
 */

$root = getenv('APP_ROOT') ?: dirname(__DIR__);

if (($argv[1] ?? '') === '--connect') {
    // Child: exactly what the app does on a request.
    require $root . '/src/config.php';
    require $root . '/src/db.php';
    try {
        echo db_connect(cfg()['db'])->query('SELECT current_user')->fetchColumn();
    } catch (PDOException) {
        echo '-';
    }
    exit(0);
}

$host = getenv('TEST_PG_HOST') ?: '127.0.0.1';
$port = getenv('TEST_PG_PORT') ?: '5432';
$name = getenv('TEST_PG_DB') ?: 'apotheke';
$adminUser = getenv('TEST_PG_USER') ?: 'apotheke';
$adminPassword = getenv('TEST_PG_PASSWORD');
$adminPassword = $adminPassword === false ? 'apotheke' : $adminPassword;

$expected = [
    '8.3' => ['a' => null, 'b' => 'env', 'c' => 'env', 'd' => null, 'e' => 'env', 'e2' => 'env', 'e3' => 'env'],
    '8.4' => ['a' => 'dsn', 'b' => 'env', 'c' => 'dsn', 'd' => 'dsn', 'e' => null, 'e2' => null, 'e3' => 'dsn'],
][PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION] ?? null;
if ($expected === null) {
    fwrite(STDERR, 'No expected row for PHP ' . PHP_VERSION . ".\n");
    exit(2);
}

try {
    $admin = new PDO("pgsql:host=$host;port=$port;dbname=$name", $adminUser, $adminPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Admin connection failed (SQLSTATE ' . $e->getCode() . ").\n");
    exit(1);
}
$tag = bin2hex(random_bytes(4));
$roles = [];
foreach (['env', 'dsn'] as $kind) {
    $roles[$kind] = ['user' => 'prec_' . $kind . '_' . $tag, 'password' => 'pw' . bin2hex(random_bytes(8))];
    $admin->exec('CREATE ROLE ' . $roles[$kind]['user'] . ' LOGIN PASSWORD ' . $admin->quote($roles[$kind]['password']));
}

$base = "pgsql:host=$host;port=$port;dbname=$name";
$withDsn = $base . ';user=' . $roles['dsn']['user'] . ';password=' . $roles['dsn']['password'];
$cases = [
    'a' => ['DSN user/password only', $withDsn, null, null],
    'b' => ['DB_USER/DB_PASSWORD only', $base, $roles['env']['user'], $roles['env']['password']],
    'c' => ['both set, differing', $withDsn, $roles['env']['user'], $roles['env']['password']],
    'd' => ['both set, DB_PASSWORD empty', $withDsn, $roles['env']['user'], ''],
    // PHP 8.4's PDO drops the passed credentials when user= or password= appears anywhere.
    'e' => ['user= in application_name', $base . ";application_name='a user=b password=c'", $roles['env']['user'], $roles['env']['password']],
    'e2' => ['password= in options', $base . ";options='-c application_name=password=x'", $roles['env']['user'], $roles['env']['password']],
    'e3' => ['c, plus user= in options', $withDsn . ";options='-c application_name=user=x'", $roles['env']['user'], $roles['env']['password']],
];
$names = [$roles['env']['user'] => 'env', $roles['dsn']['user'] => 'dsn', '-' => null];

$failed = false;
echo 'Legacy db_connect() credential precedence on PHP ' . PHP_VERSION . "\n";
try {
    foreach ($cases as $id => [$label, $dsn, $user, $password]) {
        // Only what the case sets: no inherited DB_* or libpq PG* fallbacks.
        $env = ['PATH' => (string)getenv('PATH'), 'APP_ROOT' => $root, 'APOTHEKE_CONFIG' => '/nonexistent',
                'PGPASSFILE' => '/nonexistent', 'DB_DRIVER' => 'pgsql', 'DB_DSN' => $dsn];
        if ($user !== null) {
            $env['DB_USER'] = $user;
        }
        if ($password !== null) {
            $env['DB_PASSWORD'] = $password;
        }
        $proc = proc_open([PHP_BINARY, __FILE__, '--connect'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = trim((string)stream_get_contents($pipes[1]));
        stream_get_contents($pipes[2]);
        proc_close($proc);
        $got = array_key_exists($out, $names) ? $names[$out] : 'other';
        $ok = $got === $expected[$id];
        $failed = $failed || !$ok;
        printf("  %-2s %-28s expected %-9s got %-9s %s\n", $id, $label, $expected[$id] ?? 'failure', $got ?? 'failure', $ok ? 'ok' : 'MISMATCH');
    }
} finally {
    foreach ($roles as $role) {
        $admin->exec('DROP ROLE IF EXISTS ' . $role['user']);
    }
}
exit($failed ? 1 : 0);
