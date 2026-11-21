# PR #355 evidence

Final head `a5c0ebd3e7af0d794de36471619c4d5d744cb6be`, read from Coder2's bundle `admin-reset-session-match-6.bundle` (sha256 `f1571a8e…249dda3`, verified). Full-gate head `ec7b28213d6750e1cc13c6d5e8b78003726c0197`. Live `git ls-remote origin refs/pull/355/head` returned that SHA immediately before the verdict. `refs/heads/main` was `815a5abd13ea51400cef2e2cf840c8a3bc73a3e4`. Behind count 0.

`git diff --stat 815a5abd...HEAD`: `CHANGELOG.md` +4, `docs/HANDOFF.md` +2/−2, `modules/Auth/HostedAdminReset.php` +7/−4, `tests/Modules/Auth/HostedAdminResetTest.php` +25/−2. README and VERSION have no diff. VERSION is 3.60.0 on both. The changelog header equals main. From `## [3.60.0]` the file equals main. Unreleased is one Fixed bullet linking `https://github.com/thordin9/apotheke/pull/355`.

## SQL

The session delete is still inside the hosted `users` unit, before the claim delete and `DELETE FROM users WHERE role = 'admin'`. The new condition is:

`sessions.data LIKE 'uid|i:' || CAST(id AS TEXT) || ';%'`
or `LIKE '%;uid|i:' || CAST(id AS TEXT) || ';%'`
or `LIKE '%}uid|i:' || CAST(id AS TEXT) || ';%'`

and the `id` is a `users` row with `role = 'admin'`. `HostedDisabledUsers` uses the same three shapes with three binds of one id. There is no shared helper.

PHP `session_encode` for the `php` handler, checked this turn, writes `uid|i:5;…`, `csrf|s:4:"tok1";uid|i:5;`, `flash|a:0:{}uid|i:5;`, and `obj|O:8:"stdClass":0:{}uid|i:11;`.

## Tests

PostgreSQL 16, data dir `/tmp/apotheke-pg-355.zWA9Be`, port 54358, trust, empty password.

`vendor/bin/phpunit -c tests/Modules/phpunit.xml.dist --filter HostedAdminResetTest`: OK, 26 tests, 307 assertions. That includes SQLite and PostgreSQL, connection and batch. The class still asserts the claim row is gone, a viewer remains, and `uid|i:<id>1;` remains.

Mutations, each restored, `git diff` empty afterwards. Filter `testSessionMatchIsAnchoredOnTheUidKey`:

- Old single `%uid|i:` pattern: 4 failures.
- Semicolon after the id removed on every pattern: 4 failures.
- The `%}uid|i:` alternative removed: 4 failures.

## CI

Not waited on. Pass: Analyze (actions), Analyze (javascript-typescript), CodeQL, Conventional Commits, D1, DI, Docker Compose Dockerfile, Headless mobile, Lint, Secret scan, deptrac, upgrade sqlite, upgrade pgsql. Pending: both Docker builds, PHP sqlite, PHP pgsql, Compose FrankenPHP, visual shards 1–3.

Stopped with `pg_ctl -D /tmp/apotheke-pg-355.zWA9Be -w stop` and removed that directory.

## Merge-only reads after the gate

Each read: parents checked, 0 behind main, the previous head an ancestor, the only non-merge commit on top of main a hand-off update. The code and test diff (excluding `CHANGELOG.md`, `docs/qa` and `docs/HANDOFF.md`, index lines and hunk headers normalised) equals the `ec7b2821` diff against `815a5abd`.

- `5f6252c5`: merge `97e1dfa2` of main `4637fa18` (v3.61.0) plus the hand-off. PASS.
- `f1d14c1f`: merge `4ec3144d` of main `c5476e79` (v3.64.0) plus the hand-off. Covered by the final read, which compares against main directly.
- `f0f3ccd4`: merge `2c684b6e` of main `69912dda` (v3.65.0) plus the hand-off. `git diff --stat 69912dda f0f3ccd4`: the same four files, +38/−8. Changelog preamble and released sections equal main, Unreleased is the one `#355` Fixed bullet. README and VERSION (3.65.0) have no diff. The hand-off diff is the latest-release line (v3.58.0 to v3.65.0) and the `#344` line replaced by `#355`. PASS.
- `a5c0ebd3`: merge of main `0674342c` (`#342`, ci only, no release) into `f0f3ccd4`. 0 behind. The code and test diff and the hand-off diff against main equal those of `f0f3ccd4` against `69912dda`. Changelog preamble and released sections equal main. Unreleased is the `#355` Fixed bullet followed by main's `#342` Maintenance bullet. README and VERSION (3.65.0) have no diff. PASS.
