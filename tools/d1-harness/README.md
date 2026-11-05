# Local D1 harness (P4-3, local only)

A Worker (`src/index.js`) with a local D1 binding that mirrors `edge/src/d1.ts`:
`POST /query`, `POST /batch` and `POST /exec` return the JSON shape `D1HttpExecutor` expects.
`run.php` drives `BatchUnitOfWork` cases through the spike's `HttpBatchExecutor` (or a worktree's
`D1HttpExecutor` against this base URL). `audit.php` compares SQLite functions on local D1 and PHP's
SQLite3 (p4-spike §3c).

```
npm install && npx -y node@22 node_modules/workerd/install.js   # once (install scripts are off)
./run.sh [worktree] [run.php | audit.php]
```

wrangler 4.156.0, Node 22 through `npx node@22`, `--local` with a throw-away `--persist-to` dir.
Never `--remote`. No account id, no real D1 uuid, no secrets: `database_id` is a zero UUID for local only.
The production Worker serves the same paths on `http://d1.internal` via `D1Outbound` in `edge/src`.
