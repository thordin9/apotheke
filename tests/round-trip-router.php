<?php

declare(strict_types=1);

/**
 * Router for the PHP built-in server of tests/Unit/RoundTripBudgetTest.php (test only).
 * ROUND_TRIP_LOG names a file that gets one line per database round trip:
 * - ROUND_TRIP_D1_URL set: the app runs on the D1 driver against that bridge
 *   (edge/tests/do-bridge-server.mjs), and each bridge call (the driver's
 *   transport hook) appends its path (/query, /batch or /exec);
 * - otherwise every statement CodeIgniter runs (the DBQuery event) appends "q".
 */

require_once dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Events/Events.php';

\CodeIgniter\Events\Events::on(
    'pre_system',
    static function (): void {
        $log = (string)getenv('ROUND_TRIP_LOG');
        $url = (string)getenv('ROUND_TRIP_D1_URL');
        if ($url === '') {
            \CodeIgniter\Events\Events::on('DBQuery', static function () use ($log): void {
                file_put_contents($log, "q\n", FILE_APPEND | LOCK_EX);
            });

            return;
        }
        $database = config(\Config\Database::class);
        $database->default['hostname'] = $url;
        $database->default['transport'] = static function (string $method, string $to, string $json) use ($log): string {
            file_put_contents($log, (string)parse_url($to, PHP_URL_PATH) . "\n", FILE_APPEND | LOCK_EX);
            $ctx = stream_context_create(['http' => ['method' => $method, 'content' => $json, 'timeout' => 30,
                'header' => "content-type: application/json\r\n", 'ignore_errors' => true, 'follow_location' => 0]]);
            $raw = file_get_contents($to, false, $ctx);
            if ($raw === false) {
                throw new RuntimeException('the D1 test bridge did not answer');
            }

            return $raw;
        };
    },
    \CodeIgniter\Events\Events::PRIORITY_LOW
);

return require dirname(__DIR__) . '/public/router.php';
