<?php

declare(strict_types=1);

/**
 * After the ci4@pgsql cell, the CI4 servers must have written at least one row
 * to public.apotheke_ci4_pgsql_probe. SQLSTATE 42P01 means the table is missing.
 * Any other PDO failure is a connection or query failure and is reported with
 * its SQLSTATE only. The DSN and password are not printed. A zero count fails the job.
 */

$host = getenv('TEST_PG_HOST') ?: '127.0.0.1';
$port = getenv('TEST_PG_PORT') ?: '5432';
$name = getenv('TEST_PG_DB') ?: 'apotheke';
$user = getenv('TEST_PG_USER') ?: 'apotheke';
$password = getenv('TEST_PG_PASSWORD');
if ($password === false || $password === '') {
    fwrite(STDERR, "TEST_PG_PASSWORD is not set\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$name};connect_timeout=5",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $count = (int)$pdo->query('SELECT count(*) FROM public.apotheke_ci4_pgsql_probe')->fetchColumn();
} catch (PDOException $e) {
    $sqlstate = (string)$e->getCode();
    if ($sqlstate === '42P01') {
        fwrite(STDERR, "apotheke_ci4_pgsql_probe is missing (SQLSTATE 42P01)\n");
    } else {
        fwrite(STDERR, "apotheke_ci4_pgsql_probe connection or query failed (SQLSTATE {$sqlstate})\n");
    }
    exit(1);
}

if ($count < 1) {
    fwrite(STDERR, "apotheke_ci4_pgsql_probe rows=0\n");
    exit(1);
}

fwrite(STDOUT, "apotheke_ci4_pgsql_probe rows={$count}\n");
