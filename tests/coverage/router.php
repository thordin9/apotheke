<?php
// php -S router used while collecting coverage: the real router (public/index.php) is compiled after the
// auto_prepend collector has started, so its lines are counted too. Returns what index.php returns (false = static file).
return require dirname(__DIR__, 2) . '/public/index.php';
