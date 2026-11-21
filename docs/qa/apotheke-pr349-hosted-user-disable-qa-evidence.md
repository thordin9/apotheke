# PR #349 evidence

Final head `919a9f0977f3691fbfcc31445ccd48bc15c4497a`, main `096663467bdd303323f2da4a2d1df6f04bcf1e14` (v3.66.0), 0 behind. Full-gate head `61ef46f19e813624e8451ef6ef6013c06d885528`. Live `git ls-remote origin refs/pull/349/head` returned that SHA immediately before the verdict. `refs/heads/main` was `8d28bf0532aea1661acc5fb7ff4873395512a7bf`. Behind count 1: `8d28bf05` bumps vite in `frontend/`. Merge-base with that main is `3a6156b6`.

Eighteen files versus that merge-base. `edge/src/ops-accounts.ts` is not among them. README and VERSION have no diff against the merge-base or against main. From `## [3.57.0]`, CHANGELOG equals both. The file now starts at `## [Unreleased]`. The bullet links `pull/000`.

## Migration

`0035` returns one `CREATE TABLE hosted_disabled_users` for `sqlite` and `pgsql`. No `INSERT`, no change to `users`, no down SQL. `Backups::UNCARRIED_MIGRATIONS` includes `0035`. `upg_ci4_only()` maps it to `hosted_disabled_users`. `tests/upgrade/ci4-only.php` seeds and checks one row. `scripts/upgrade-rollback.sh` names the table among the CI4-only additions that stay through rollback.

`vendor/bin/phpunit --filter 'testEveryCi4OnlyMigrationIsRegistered|testNoCi4OnlyMigrationChangesATable26Reads'` with PostgreSQL on port 54356: OK, 2 tests, 110 assertions. That run covers SQLite and PostgreSQL. CI on this head: both upgrade-and-rollback jobs pass. PHP 8.3 is not on this machine, so `scripts/upgrade-rollback.sh` was not run locally.

## Behaviour

PostgreSQL 16, data dir `/tmp/apotheke-pg-349.9YYmws`, port 54356, trust, empty password. `HostedDisabledUsersTest` and `OpsAccountsDisableHttpTest`: OK, 19 tests, 273 assertions.

A second process pair disabled two active admins, 12 rounds, four modes (SQLite and PostgreSQL, connection and batch). `zero=0` in every mode: at least one active admin remained. After enable, `password_same=Y`, `status=active`, `name=looker`. Two session rows were inserted before disable, one `uid|i:<id>;` and one `otheruid|i:<id>;`. `sessions_left=0`, so the second row was removed too (N-1918, fixed at `6bc6b8d2`, below).

The last-admin test disables one admin, then refuses the remaining active admin (409) while a pending admin exists. The session of the refused admin stays. A second disable of an already flagged user stays flagged. Enable twice leaves the flag unset. Hosted off admits a user who has a row.

`Accounts::admit` checks the flag after the password check, and throws 403 `This account cannot sign in.` `CurrentUser::resolve` requires the flag to be clear. There is no token table in the migrations. Re-enable deletes the flag row only.

## Mutations

Each edit was restored. `git diff` was empty.

- Last-admin `EXISTS` replaced with `1 = 1`: `testLastActiveAdminIsRefused...` 4 failures (both drivers, both modes).
- Sign-in check removed: `testDisableRefusesSignIn...` 4 failures.
- `CurrentUser` check removed: `testCurrentUserDropsADisabledSession` 4 failures.
- Missing-user 404 removed: `testUnknownUserIsNotFound...` 4 failures.
- `0035` dropped from `UNCARRIED_MIGRATIONS`: `testEveryCi4OnlyMigrationIsRegistered` 1 failure.
- Migration SQL replaced with `DROP TABLE users`: `testNoCi4OnlyMigrationChangesATable26Reads` 1 failure.

## Endpoint

`OpsAccountsFilter` handles any `/internal/accounts/` URI before the session and CSRF filters. `applies` requires hosted, host `ops.internal`, one of the four exact paths, and no `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Forwarded-For`, or `X-Apotheke-Client-IP`. `handle` requires POST and a signature over that path and body. `userId` accepts only a JSON object whose sole key is `user_id` with a positive int. The HTTP test covers a viewer disable and enable, the last admin 409, unknown 404, `{}` 400, a bad signature, a forwarded header, a public host, and hosted off. Those cases did not flag the user.

`edge/src/ops-accounts.ts` still has only `claim` and `reset-admins`. `docs/operator-console.md` still lists `POST /installations/:id/users/:uid/disable` under deferred.

Stopped with `pg_ctl -D /tmp/apotheke-pg-349.9YYmws -w stop` and removed that directory.

CI at the verdict, not waited on: Analyze (actions), Analyze (javascript-typescript), CodeQL, Conventional Commits, D1, DI, both Compose jobs, Headless mobile, Lint, Secret scan, both upgrade jobs, deptrac, and visual shards 2 and 3 pass. Both Docker builds, PHP sqlite, PHP pgsql, and visual shard 1 are pending.

Subject `feat(auth): disable or re-enable a hosted user on ops.internal (#349)` is 69 characters.

## Delta at 6bc6b8d2

Merge `dd929d97` (parents `61ef46f1`, `1a9c9a3d` v3.59.0): the normalized own diff of `3a6156b6..61ef46f1` equals `1a9c9a3d..dd929d97`, excluding `CHANGELOG.md`, `docs/qa` and `docs/HANDOFF.md`. `a10c5a25` anchors the session match: `uid|i:<id>;` at the start of the payload or after `;` or `}`. PHP's `php` serializer writes those three shapes. `otheruid|i:<id>;` stays at the start and after another field, and `uid|i:<id>0;` stays. `HostedDisabledUsersTest`: OK, 20 tests, 248 assertions, SQLite and PostgreSQL, connection and batch. Restoring the old `%uid|i:` match fails the new test, 4 failures. Dropping the semicolon fails it the same way. The changelog header and released sections equal main, and Unreleased links `pull/349`. L-1917 and N-1918 closed.

## Merge-only reads

Each read: parents checked, 0 behind main, the previous head an ancestor, the only non-merge commits on top of main hand-off updates. The code and test diff (excluding `CHANGELOG.md`, `docs/qa` and `docs/HANDOFF.md`, index lines and hunk headers normalised) equals the `6bc6b8d2` diff against its merge-base `1a9c9a3d`.

- `a4b2d461`: merge `8f19624d` of main `4637fa18` (v3.61.0) plus the hand-off. PASS.
- `919a9f09`: merge `783cc8c4` of main `09666346` (v3.66.0) plus the hand-off. Eighteen files, +697/−27. Changelog preamble and released sections equal main, and Unreleased is one Added bullet for `#349`. README and VERSION (3.66.0) have no diff. The hand-off diff is the latest-release line (v3.65.0 to v3.66.0) and the `#355` line replaced by `#349`. CI at the read: Analyze (actions) and CodeQL pass, the rest pending. PASS.
