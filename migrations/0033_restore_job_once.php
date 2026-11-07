<?php

// 0033 – one queued or running restore.confirm job per payload. Two confirms of the same upload
// cannot both insert a claimable row. CI4 only: an index on jobs, which 2.6.x does not read.
// A finished job leaves the index, so a later confirm of a new attempt can insert again.
// Existing duplicates: the oldest queued or running row for a payload is kept. The others are
// marked failed so they are not claimed and the index can be created.
return static function (PDO $pdo, string $driver): void {
    $rows = $pdo->query("SELECT id, payload FROM jobs WHERE type = 'restore.confirm' AND status IN ('queued', 'running') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $seen = [];
    $drop = $pdo->prepare("UPDATE jobs SET status = 'failed', error = 'This restore is already queued.', finished_at = created_at, updated_at = created_at WHERE id = ?");
    foreach ($rows as $row) {
        $payload = (string)$row['payload'];
        if (isset($seen[$payload])) {
            $drop->execute([(int)$row['id']]);
            continue;
        }
        $seen[$payload] = true;
    }
    $pdo->exec("CREATE UNIQUE INDEX idx_jobs_restore_confirm_active ON jobs (payload) WHERE type = 'restore.confirm' AND status IN ('queued', 'running')");
};
