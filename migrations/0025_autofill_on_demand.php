<?php
// 0025 – AI autofill on demand (Apotheke 2.4): seeds the new Control Panel settings autofill_auto, autofill_model and
// autofill_image_size. autofill_auto ("Analyze photos automatically") is written as OFF on every install, new and
// existing: from 2.4 nothing is sent when a photo is picked; each photo gets an Analyze button instead. An admin who
// wants the 2.3 behaviour back turns it on under Control Panel → AI autofill. autofill_model '' (the service's default)
// and autofill_image_size 0 (the size the service recommends; 1024 px for a service that does not list sizes) keep
// what is sent unchanged. No schema change.
// Plain SQL with literal values (no settings_schema()), so it runs in any context, e.g. bin/migrate.php or a bare db().
return static function (PDO $pdo, string $driver): void {
    $del = $pdo->prepare('DELETE FROM settings WHERE name = ?');
    $ins = $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)');
    $have = $pdo->query("SELECT name FROM settings WHERE name IN ('autofill_model', 'autofill_image_size')")->fetchAll(PDO::FETCH_COLUMN);
    $del->execute(['autofill_auto']);
    $ins->execute(['autofill_auto', json_encode(false), now_utc()]);           // off on every install, new and existing
    if (!in_array('autofill_model', $have, true)) $ins->execute(['autofill_model', json_encode(''), now_utc()]);
    if (!in_array('autofill_image_size', $have, true)) $ins->execute(['autofill_image_size', json_encode(0), now_utc()]);
    if (function_exists('settings_cache')) settings_cache(null, true);
};
