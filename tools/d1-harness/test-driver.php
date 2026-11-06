<?php
// Local-only D1: zero-UUID workerd on loopback, no account or credentials.
declare(strict_types=1);
$root = $argv[1];
$url = 'http://127.0.0.1:8799';
$cfg = json_decode(preg_replace('~^//.*$~m', '', file_get_contents(__DIR__ . '/wrangler.jsonc')), true);
if (($cfg['d1_databases'][0]['database_id'] ?? null) !== '00000000-0000-0000-0000-000000000000') {
    throw new RuntimeException('Only the zero-UUID local D1 config may run.');
}
foreach (['CLOUDFLARE_API_TOKEN', 'CF_API_TOKEN', 'CF_WORKERS_DEPLOY_TOKEN', 'CF_TOKEN_EDGE_DEPLOY', 'CF_TOKEN_STATIC_DEPLOY'] as $key) {
    if (trim((string)getenv($key)) !== '') {
        throw new RuntimeException('The local D1 cell must not have Cloudflare credentials.');
    }
}
$body = json_encode(['sql' => 'SELECT 1 AS alive', 'params' => []], JSON_THROW_ON_ERROR);
$ctx = stream_context_create(['http' => ['method' => 'POST', 'content' => $body, 'timeout' => 5,
    'header' => "content-type: application/json\r\n", 'ignore_errors' => true, 'follow_location' => 0]]);
if ((json_decode(file_get_contents($url . '/query', false, $ctx), true)['rows'][0]['alive'] ?? null) !== 1) {
    throw new RuntimeException('The loopback D1 health check failed.');
}
putenv('TEST_D1_URL=' . $url);
chdir($root);
$localOnly = 'testAUnitJoinsAnOpenTransaction|testAUnitInsideAPlainTransactionKeepsNothingWhenTheCallerCatches|testAFailedStatementInAJoinedUnitLeavesTheOuterTransactionUsable|testAUnitInsideAnAlreadyFailedTransactionIsRefused|testACallbackThatSwallowsItsFailedStatementFailsOnlyItsUnit|testAJoinedUnitReleasesItsSavepoint';
$argv = array_merge(['vendor/bin/phpunit', '--no-configuration', '--bootstrap', 'tests/Modules/bootstrap.php',
    '--fail-on-skipped', '--fail-on-empty-test-suite', '--testdox',
    '--filter', '/(?:SqlExecutor|D1HttpExecutor|D1MigrationRunner|D1Integration)|d1 local batch/',
    '--exclude-filter', '/(?:' . $localOnly . ').*d1 local batch/'], array_slice($argv, 2));
$_SERVER['argv'] = $GLOBALS['argv'] = $argv;
$_SERVER['argc'] = $GLOBALS['argc'] = count($_SERVER['argv']);
require 'vendor/phpunit/phpunit/phpunit';
