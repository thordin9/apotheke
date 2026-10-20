<?php

declare(strict_types=1);

/**
 * After the ci4@pgsql cell, the CI4 servers must have written at least one row
 * to public.apotheke_ci4_pgsql_probe. A missing table or a zero count fails the job.
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
        "pgsql:host={$host};port={$port};dbname={$name}",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $count = (int)$pdo->query('SELECT count(*) FROM public.apotheke_ci4_pgsql_probe')->fetchColumn();
} catch (PDOException $e) {
    fwrite(STDERR, "apotheke_ci4_pgsql_probe is missing\n");
    exit(1);
}

if ($count < 1) {
    fwrite(STDERR, "apotheke_ci4_pgsql_probe rows=0\n");
    exit(1);
}

fwrite(STDOUT, "apotheke_ci4_pgsql_probe rows={$count}\n");
