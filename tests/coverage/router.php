<?php
// php -S router used while collecting coverage. PHP 8.3's built-in server does not apply auto_prepend_file to the
// router script, so the collector is loaded here too (it runs once per request either way); public/index.php is
// compiled after pcov has started, so its lines are counted. Returns what index.php returns (false = static file).
require __DIR__ . '/prepend.php';
return require dirname(__DIR__, 2) . '/public/index.php';
