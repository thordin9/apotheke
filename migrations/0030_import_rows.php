<?php
// 0030 – the API's import commit job (API plan §6): one row per CSV line it has imported (or found in error), so a
// job that stopped resumes after the last line it committed and never imports a line twice. CI4 only (3.2.0 and
// later): 2.6.x ignores the table and a backup does not carry it. No foreign key to imports (a 2.6 table).
return static function (PDO $pdo, string $driver): void {
    $pdo->exec("CREATE TABLE import_rows (
        import_id INTEGER NOT NULL,
        line      INTEGER NOT NULL,
        state     TEXT NOT NULL CHECK (state IN ('new', 'update', 'same', 'error')),
        error     TEXT NOT NULL DEFAULT '',
        made      TEXT NOT NULL DEFAULT '{}',
        PRIMARY KEY (import_id, line)
    )");
};
