<?php
// php -S router used while collecting coverage: loads the collector, then the router the server would have used
// (APOTHEKE_COVERAGE_FRONT, set by tests/lib.php). The built-in server does not apply auto_prepend_file to the router
// script, so the collector is loaded here too (it runs once per request either way). Returns what that router returns.
require __DIR__ . '/prepend.php';
return require (getenv('APOTHEKE_COVERAGE_FRONT') ?: dirname(__DIR__, 2) . '/public/router.php');
