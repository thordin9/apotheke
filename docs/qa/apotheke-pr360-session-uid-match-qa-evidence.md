# PR #360 evidence

Gated head `7f457a73f09586293fbcee07e45b915650f0feaf`. Live `git ls-remote origin refs/pull/360/head` returned that SHA immediately before the verdict. `refs/heads/main` was `4f28784d195d6452ce4253ff9fc588307723ebfb`. Behind count 0. VERSION is `3.67.0` on both. `git diff --stat` for CHANGELOG, README, and VERSION is empty.

## SQL

PHP built main's concatenated strings and `SessionUidMatch` on this head.

Reset, both 260 bytes, identical:

`DELETE FROM sessions WHERE EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND (sessions.data LIKE 'uid|i:' || CAST(id AS TEXT) || ';%' OR sessions.data LIKE '%;uid|i:' || CAST(id AS TEXT) || ';%' OR sessions.data LIKE '%}uid|i:' || CAST(id AS TEXT) || ';%'))`

Disable, both 174 bytes, identical:

`DELETE FROM sessions WHERE data LIKE 'uid|i:' || CAST(? AS TEXT) || ';%' OR data LIKE '%;uid|i:' || CAST(? AS TEXT) || ';%' OR data LIKE '%}uid|i:' || CAST(? AS TEXT) || ';%'`

`bindings(5)` is `[5, 5, 5]`. `rg uid\\|i:` under `modules/` matches only `SessionUidMatch.php`. Deptrac: 0 violations, 0 errors.

`#349` is merged (`2026-11-21T10:17:03Z`). The hand-off replaces that open-PR line and sets the latest release to v3.67.0, matching VERSION. Main's hand-off still said v3.66.0.

## Tests

PostgreSQL 16, data dir from `mktemp -d /tmp/apotheke-pg-360.*`, port 54359, trust, empty password.

`vendor/bin/phpunit -c tests/Modules/phpunit.xml.dist --filter 'HostedAdminResetTest|HostedDisabledUsersTest|SessionUidMatchTest'`: OK, 51 tests, 565 assertions.

Mutations of `SessionUidMatch.php`, filter `testSessionMatchIsAnchoredOnTheUidKey` (both caller classes). Each was restored. `git diff` was empty.

- `%uid|i:` only: 8 tests, 4 errors in `HostedDisabledUsersTest` (one placeholder, three bindings), 4 failures in `HostedAdminResetTest`. Both drivers, connection and batch.
- Semicolon removed: 8 failures, both classes.
- `}` pattern removed: 8 tests, 4 errors in `HostedDisabledUsersTest` (two placeholders, three bindings), 4 failures in `HostedAdminResetTest`.

`SessionUidMatchTest` seeds `uid|i:1;`, `;uid|i:1;`, `{}uid|i:1;`, `uid|i:11;`, `otheruid|i:1;`, and `x|i:2;otheruid|i:1;`. The placeholder query for id 1 returns only the first three. The column query for id 11 returns only `uid|i:11;`.

## CI

Not waited on. Pass: Analyze (actions), Analyze (javascript-typescript), CodeQL, Conventional Commits, D1, DI, both Compose jobs, Headless mobile, Lint, Secret scan, deptrac, upgrade sqlite, upgrade pgsql. Pending: both Docker builds, PHP sqlite, PHP pgsql, visual shards 1–3.

Stopped with `pg_ctl -D` the recorded data dir `-w stop` and removed that directory.

## Merge-only read at 5074d34b

Read from `/workspace/coder2-artifacts/uid-session-match-4.bundle` (sha256 `7644e63ca28f22b55424f9b9e716c16c241657fa86507a47be7d17ed80aa2ef4`), which lists `refs/heads/refactor/uid-session-match` at `5074d34b0f4204e59983cf683df2faffc80ef8b2`. Merge parents `4338c2fd` and `98135012a5115a07eb7d57745495e2de3112cc5a` (main, `#343`). Behind count 0. `4338c2fd` is an ancestor, and there is no non-merge commit after it. The normalised diff of `7f457a73` against its merge base equals `origin/main..5074d34b`, excluding CHANGELOG.md, docs/qa and docs/HANDOFF.md. CHANGELOG, README and VERSION have no diff against main. The hand-off diff is the v3.67.0 release line and the `#360` line. Main's `4f28784d..98135012` touched only `edge/` and `docs/qa/apotheke-pr343-wrangler-4164-qa.md`.
