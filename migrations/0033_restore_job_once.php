<?php

// 0033 – one queued or running restore.confirm job per payload. Two confirms of the same upload
// cannot both insert a claimable row. CI4 only: an index on jobs, which 2.6.x does not read.
// A finished job leaves the index, so a later confirm of a new attempt can insert again.
return static function (PDO $pdo, string $driver): void {
    $pdo->exec("CREATE UNIQUE INDEX idx_jobs_restore_confirm_active ON jobs (payload) WHERE type = 'restore.confirm' AND status IN ('queued', 'running')");
};
