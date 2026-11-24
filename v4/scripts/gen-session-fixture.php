<?php

declare(strict_types=1);

/**
 * The session fixture (plan §5 S1.2): what v3's own session classes answer, captured once and committed.
 *
 *     v4/scripts/gen-session-fixture.sh            # regenerate test/fixtures/php-session.json
 *     v4/scripts/gen-session-fixture.sh --check    # hash check only (no PHP needed; what CI runs)
 *
 * Why PHP and not a hand-written table: the cookie name, the Secure decision and the epoch are three
 * places where the plan was wrong once already (revision 19 corrected the name and the unconditional
 * `__Host-`). A fixture produced by calling `SessionGate`, `RequestScheme`, `SessionEpoch` and
 * `UserRepository` cannot drift from v3 without the PHP source changing — and then `--check` fails.
 *
 * `sessionData` is a real `session_encode()` under `session.serialize_handler=php`, so the DoD's
 * "a session v4 issues is the row v3 would have written" is compared against PHP's serialiser rather
 * than against a description of it.
 *
 * The fixture records the SHA-256 of every PHP file it reads. Nothing here is normalised.
 */

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$sources = [
    'modules/Core/Http/SessionGate.php',
    'modules/Core/Http/RequestScheme.php',
    'modules/Core/Filters/SessionCookie.php',
    'modules/Auth/SessionEpoch.php',
    'modules/Auth/RateLimiter.php',
    'modules/Auth/UserRepository.php',
    'modules/Auth/Accounts.php',
];

/** The request shapes the Secure decision is derived from; each one is a `$_SERVER` subset. */
$cases = [
    [
        'name' => 'plain http by address, no public url',
        'url' => 'http://192.168.1.5:8080/',
        'server' => ['SERVER_PORT' => 8080, 'HTTP_HOST' => '192.168.1.5:8080'],
        'trust' => false,
        'public_url' => '',
    ],
    [
        'name' => 'direct https',
        'url' => 'https://apotheke.example/',
        'server' => ['HTTPS' => 'on', 'SERVER_PORT' => 443, 'HTTP_HOST' => 'apotheke.example'],
        'trust' => false,
        'public_url' => '',
    ],
    [
        'name' => 'http behind a trusted proxy that says https',
        'url' => 'http://internal:9091/',
        'server' => ['SERVER_PORT' => 9091, 'HTTP_HOST' => 'apotheke.example', 'HTTP_X_FORWARDED_PROTO' => 'https'],
        'trust' => true,
        'public_url' => '',
    ],
    [
        'name' => 'the same proxy header, not trusted',
        'url' => 'http://internal:9091/',
        'server' => ['SERVER_PORT' => 9091, 'HTTP_HOST' => 'apotheke.example', 'HTTP_X_FORWARDED_PROTO' => 'https'],
        'trust' => false,
        'public_url' => '',
    ],
    [
        'name' => 'plain http on public url host',
        'url' => 'http://internal:9091/',
        'server' => ['SERVER_PORT' => 9091, 'HTTP_HOST' => 'apotheke.example'],
        'trust' => false,
        'public_url' => 'https://apotheke.example',
    ],
    [
        'name' => 'plain http on another host than public url',
        'url' => 'http://internal:9091/',
        'server' => ['SERVER_PORT' => 9091, 'HTTP_HOST' => 'other.example'],
        'trust' => false,
        'public_url' => 'https://apotheke.example',
    ],
    [
        'name' => 'plain http by address while public url is https',
        'url' => 'http://192.168.1.5/',
        'server' => ['SERVER_PORT' => 80, 'HTTP_HOST' => '192.168.1.5'],
        'trust' => false,
        'public_url' => 'https://apotheke.example',
    ],
    [
        'name' => 'plain http on port 443',
        'url' => 'http://apotheke.example:443/',
        'server' => ['SERVER_PORT' => 443, 'HTTP_HOST' => 'apotheke.example:443'],
        'trust' => false,
        'public_url' => '',
    ],
];

$secureCases = [];
foreach ($cases as $case) {
    $scheme = \Apotheke\Core\Http\RequestScheme::fromSetting($case['trust']);
    $secureCases[] = [
        'name' => $case['name'],
        'input' => [
            'url' => $case['url'],
            'host' => (string) ($case['server']['HTTP_HOST'] ?? ''),
            'forwardedProto' => (string) ($case['server']['HTTP_X_FORWARDED_PROTO'] ?? ''),
            'trustedProxy' => $case['trust'],
            'publicUrl' => $case['public_url'],
        ],
        'secure' => $scheme->secureCookie($case['server'], $case['public_url']),
    ];
}

$passwordHash = '$2y$12$abcdefghijklmnopqrstuuJ0hR3S7b9m0m2mC7t6y5o4k3j2h1g0f9';
$epochCases = [];
foreach ([[1, ''], [7, 'a1b2c3d4e5f60718'], [42, '']] as $pair) {
    [$id, $stored] = $pair;
    $epochCases[] = [
        'id' => $id,
        'password_hash' => $passwordHash,
        'session_epoch' => $stored,
        'epoch' => (new \Apotheke\Auth\SessionEpoch())->of([
            'id' => $id,
            'password_hash' => $passwordHash,
            'session_epoch' => $stored,
        ]),
    ];
}

// A real session_encode() of the five keys SessionGate::signIn() writes, in its order.
$save = sys_get_temp_dir() . '/apotheke-session-fixture-' . getmypid();
@mkdir($save, 0775, true);
ini_set('session.serialize_handler', 'php');
ini_set('session.use_strict_mode', '1');
session_save_path($save);
session_name('apothekesid');
session_start();
$_SESSION = [];
$_SESSION['uid'] = 7;
$_SESSION['auth_epoch'] = 'a1b2c3d4e5f60718';
$_SESSION['csrf'] = str_repeat('5f', 32);
$_SESSION['last_activity'] = 1764000000;
$_SESSION['signed_in_at'] = 1763999000;
$data = session_encode();
$sessionId = session_id();
session_destroy();

$folded = [];
foreach ([' Alice ', 'ADMIN', 'Mixed Case'] as $name) {
    $folded[] = ['name' => $name, 'key' => \Apotheke\Auth\UserRepository::key($name)];
}

$fixture = [
    'generatedBy' => 'v4/scripts/gen-session-fixture.sh',
    'php' => PHP_VERSION,
    'note' => 'Raw answers of v3\'s session classes. Nothing normalised: the cookie names, the Secure bit per request shape, the epoch, and a real session_encode() of the five keys signIn() writes.',
    'sources' => array_combine(
        $sources,
        array_map(static fn (string $f): string => hash_file('sha256', $root . '/' . $f), $sources),
    ),
    'cookie' => [
        'plain' => \Apotheke\Core\Http\SessionGate::COOKIE,
        'secure' => \Apotheke\Core\Http\SessionGate::SECURE_COOKIE,
        'names' => [
            ['secure' => false, 'name' => \Apotheke\Core\Http\SessionGate::cookieName(false)],
            ['secure' => true, 'name' => \Apotheke\Core\Http\SessionGate::cookieName(true)],
        ],
    ],
    'secureCookie' => $secureCases,
    'epoch' => $epochCases,
    'accountKey' => $folded,
    'sessionData' => [
        'values' => [
            'uid' => 7,
            'authEpoch' => 'a1b2c3d4e5f60718',
            'csrf' => str_repeat('5f', 32),
            'lastActivity' => 1764000000,
            'signedInAt' => 1763999000,
        ],
        'sessionId' => $sessionId,
        'data' => $data,
    ],
];

echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";

@rmdir($save);
