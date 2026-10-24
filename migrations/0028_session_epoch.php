<?php
// 0028 – One session epoch per account. Sign-out everywhere and a new password store a new value.
// A session is valid only while it holds the current one. Existing rows are filled with the fingerprint
// their sessions already use, so an upgrade does not sign everybody out. Plain SQL plus that fingerprint.
return static function (PDO $pdo, string $driver): void {
    $have = false;
    if ($driver === 'pgsql') {
        $have = (bool)$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'session_epoch'")->fetchColumn();
    } else {
        foreach ($pdo->query('PRAGMA table_info(users)') as $col) {
            if (($col['name'] ?? '') === 'session_epoch') $have = true;
        }
    }
    if (!$have) $pdo->exec("ALTER TABLE users ADD COLUMN session_epoch TEXT NOT NULL DEFAULT ''");
    $upd = $pdo->prepare('UPDATE users SET session_epoch = ? WHERE id = ?');
    foreach ($pdo->query("SELECT id, password_hash FROM users WHERE session_epoch = ''") as $r) {
        $upd->execute([substr(hash('sha256', $r['password_hash'] . '|' . $r['id']), 0, 16), $r['id']]);
    }
};
