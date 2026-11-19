# PR #318 evidence

Gated head `79e34e27a7fb84caea41b1fcbe5d305c62da4775`. Live `git ls-remote origin refs/pull/318/head` returned that SHA immediately before the verdict. `refs/heads/main` was `b4835511ce51ab5241ba029c2f0608edf9f96ed6`. `git merge-base --is-ancestor b4835511 HEAD` was true. Working tree clean after the mutations were restored.

Own diff versus main: `CHANGELOG.md`, `app/Config/Services.php`, `docs/HANDOFF.md`, `docs/history/handoff-log.md`, `docs/operator-console.md`, `edge/src/index.ts`, `edge/tests/installation-secrets.test.mjs`, `modules/Auth/Commands/HostedAdminResetCommand.php`, `modules/Auth/HostedAdminReset.php`, `tests/Modules/Auth/HostedAdminResetTest.php`, `tests/Unit/Ci4AccountsCliTest.php`. 11 files, +466/−20.

## Hosted env

`installationEnv` returns `APP_PASSWORD` only when the object name is `primary`. Any other name returns `APOTHEKE_HOSTED=1` and `APOTHEKE_INSTALLATION` set to that name, and no `APP_PASSWORD`. `startAndWait` spreads that account map and then sets `APP_KEY`, hosts, cookie, CORS, worker flags, port, timezone, `TRUST_PROXY`, `APOTHEKE_EDGE_CLIENT_IP`, and storage mode. `APP_KEY` for a non-primary name is the existing per-installation derivation. Lifecycle `bootstrap` is `pending_c1` in `lifecycle-workflow.ts` and does not start a container.

`node --test --experimental-strip-types --test-name-pattern 'hosted env' edge/tests/r2.test.mjs`: 3 pass, 0 fail. Primary env has `APP_PASSWORD` and neither new variable. A non-primary env has no `APP_PASSWORD`, hosted `1`, and its own name. A posted header and body do not change either env.

## Reset

`HostedAdminReset::run` throws 404 when `HostedLimits::isHosted()` is false. That flag is `ApothekeConfig`'s `hosted` value, set with `FILTER_VALIDATE_BOOL` on `APOTHEKE_HOSTED`. The delete is `DELETE FROM users WHERE role = 'admin'` inside `units->run('users', …, UserRepository::ACTIVE_ADMINS)`. There is no count outside the unit.

`HostedAdminResetTest` on scratch PostgreSQL (the suite also runs SQLite): 13 tests, 91 assertions, OK. The command test covers both drivers: not hosted is exit 1, empty stdout, the hosted-only stderr line, and both accounts remain; hosted is exit 0, the fixed stdout sentence, empty stderr, and only the viewer remains. A second success is the same sentence.

A one-off on both drivers planted a claim row, reset the only admin, and read back: no users, the claim row still present, and `UserRepository::find` of the deleted id is null. `CurrentUser::resolve` requires that row, status `active`, and a matching epoch. Hosted forces sign-in on, so the anonymous-admin path (sign-in off) does not apply. The `sessions` table has no user foreign key; the command does not delete those rows. `claim()` and `create()` both take `active-admins`.

`testResetWaitsForAnAdminBeingAddedOnPostgres`, eight times: each OK, 2 tests, 8 assertions. The other connection holds the key, inserts an admin, and commits; reset then leaves no accounts.

Command registration is `Services::commands`. Search of `docker/` and `edge/src` for `hosted-reset` found no start-up call.

## Mutations

Each change was written only in `HostedAdminReset.php` and restored before the next. `git diff` of that file was empty at the end.

- Hosted throw removed: `testOffWithoutHosted` fails.
- `WHERE role = 'admin'` removed: `testItRemovesAdminsAndLeavesOtherAccounts` fails for SQLite and PostgreSQL, connection and batch.
- Lock key replaced with empty: the PostgreSQL race fails for connection and batch.
- Delete moved to a query outside `units->run`: the same race fails for both modes.

## Docs

Preamble of `CHANGELOG.md` equals main. The file from `## [3.49.2]` equals main (200313 bytes). Main's Unreleased section is only the heading. This head adds the two #318 bullets and a Changed bullet for the #326 refusal. README and VERSION match main. `VERSION` is `3.49.2`. Operator-console text matches the env split and the reset.

L-2194: no match under `docs/`, none in the PR body, PR comments were empty, and a repository issue search returned no hit.

## Suites

Scratch PostgreSQL 16, trust on localhost, port 54346, data dir `/tmp/apotheke-pg-318.*`. Stopped with `pg_ctl -D` that dir `-w stop`, then the dir was removed and confirmed absent. Scratch logs removed. `vendor/` left in place.

SQLite root: 642 tests, 10511 assertions, 1 failure, 32 skipped, 1 incomplete. The failure is `Ci4ModuleSuiteTest`, which reruns the module suite. SQLite module: 1514 tests, 46965 assertions, 1 failure, 95 skipped. PostgreSQL root: 642 tests, 11262 assertions, 1 failure, 16 skipped (same wrapper). PostgreSQL module: 2058 tests, 54128 assertions, 1 failure, 66 skipped. The module failure is `BackupHelpersTest::testAZipIsRefusedOnItsEntriesBeforeItsContents`: expected "The backup is too large to unpack.", got "That file is not a zip archive." CMYK did not run (no Imagick). No deadlock flake.

CI run 44482262816 on this head, recorded without waiting: PHP sqlite, PHP pgsql, Lint, deptrac, DI, D1, both upgrade jobs, both Docker builds, both Compose jobs, three visual shards, secret scan, conventional commits, and the analyze and CodeQL checks succeeded. Deploy skipped. Headless mobile checks failed: Chrome timed out after 30s waiting for its endpoint. The visual shards on the same run passed.

## Delta at 62d37b8a

Live head `62d37b8a210a686e33bf8abb4b90e24d77a05721` matches `ls-remote`. Main `b4835511ce51ab5241ba029c2f0608edf9f96ed6` matches and is an ancestor. Last PASS was `79e34e27`. New commits: `46925500`, `a780ed48`, `62d37b8a`. Diff since `79e34e27` is only those docs, `HostedAdminReset`, its command text, and `HostedAdminResetTest` (7 files, +104/−21). No other code changed.

### Verdict

PASS. L-1897, N-1898 and L-1899 are closed. New: L-1900, N-1901. Next id 1902.

### Reset (`a780ed48`)

The three writes sit in one `users` unit under `active-admins`: delete matching `sessions` rows, delete the `hosted_first_admin_claim` settings row, then `DELETE FROM users WHERE role = 'admin'`. That unit is one transaction (one batch on D1), so a failure rolls all three back. A second run removes a claim left behind and leaves a viewer and that viewer's session. Success still prints one fixed sentence and empty stderr. The command does not print a session id, a claim value, or an exception.

Hosted mode (`DB_DRIVER=d1`) stores sessions only in the `sessions` table, raw PHP session text. There is no cache handler. The file handler is used only when the driver is not `d1`, and this command does not delete those files. A deleted admin cannot resolve from either store.

The session SQL is `LIKE '%uid|i:' || CAST(id AS TEXT) || ';%'`, not a bound equality. On SQLite and PostgreSQL, `uid|i:<id>;` for an admin (active or pending) is removed, `uid|i:<id>1;` stays, a viewer's `uid|i:<viewer>;` stays, and a string-typed uid stays. `CAST` as text matches the digits PHP writes for an int. A payload whose key merely ends in `uid` (`otheruid|i:<admin>;`) is also removed (N-1901). Each installation has its own database, so another installation's sessions are not in this table.

PostgreSQL, four times, connection and batch: a claim inserted under `active-admins` is gone after reset, and the viewer session stays. A redeem that commits an admin, that admin's `uid|i:<id>;` session and a claim under the same lock is then cleared; the neighbour id and the viewer stay.

Dropping the session delete, or dropping the trailing `;` in the pattern, fails `testItRemovesTheClaimRowAndAdminSessions` on SQLite and PostgreSQL, both modes. Moving the claim delete outside the unit still passes all 17 tests, 169 assertions (L-1900). A separate race on that edit left the claim (`claim=1`); the same race on this head removes it (`claim=0`).

`HostedAdminResetTest`: OK, 17 tests, 169 assertions (both drivers). `testHostedResetAdminsOpensTheClaimPath`: OK, 1 test, 20 assertions.

### Docs

`46925500` drops the shipped #326 bullet. CHANGELOG preamble and every section from `[3.49.2]` equal main. Unreleased is the two #318 bullets, and the auth bullet names sessions and the claim.

`62d37b8a` matches the start path: env is passed only when the container is not already running, so a container started with the shared `APP_PASSWORD` keeps that env until restart. The reset refuses unless hosted, so the documented order is restart, then `accounts:hosted-reset-admins`, before a tenant gets access. That command removes the seeded admin, that admin's session rows, and any claim.

### CI

On `62d37b8a`, as it stands: Lint, deptrac, DI, D1, CodeQL, secret scan, conventional commits, Headless mobile, both Compose jobs, both upgrade jobs, and visual shard 2 pass. PHP sqlite and pgsql, visual shards 1 and 3, and both Docker builds are in progress. Deploy is skipped.

Scratch PostgreSQL was stopped with `pg_ctl -D` and its data directory removed.

Live head `319d7e52fdb749522344129a2e73d30ad5525fbd` matches `ls-remote`. Main `b4835511` matches and is an ancestor. Last PASS was `62d37b8a`. One commit, three files: `HostedAdminReset.php`, `HostedAdminResetTest.php`, `tests/Modules/_support/lock-snapshot.php`.

**PASS.** L-1900 is not closed. No new finding. Next id 1902.

The fourth constructor argument defaults to null. `Services::hostedAdminReset` and the spark command pass no hook. Nothing reads it from env, config, CLI, or a request. When it is null the three calls are skipped and the session, claim, and admin deletes stay in that order inside the `active-admins` unit. The only caller that sets it is the test.

Deleting the claim with a query immediately before `units->run`, or immediately after it, still passes the five new tests (48 assertions) on SQLite and PostgreSQL. Five repeats of the after-unit edit also passed. Deleting the claim before the `before` hook fails `testTheClaimRowAndTheAdminsLeaveInTheSameUnit` on both drivers, both modes. Dropping the session delete or widening the match still fails that session test on both drivers. Dropping the lock still fails both PostgreSQL lock tests.

The five new tests, 10/10: OK, 48 assertions. `HostedAdminResetTest` once: OK, 22 tests, 217 assertions.

CI on this head, not waited on: Lint, deptrac, DI, D1, CodeQL, secret scan, conventional commits, Headless mobile, FrankenPHP Compose, and both upgrade jobs pass. PHP sqlite and pgsql, the visual shards, both Docker builds, and the nginx Compose job are in progress. Deploy is skipped.

Scratch PostgreSQL was stopped with `pg_ctl -D` and its data directory removed.

## Delta at b726d19a

Live head `b726d19a9163331aabe1d55e76f889de9b061f19` matches `ls-remote`. Last PASS was `319d7e52`. Main is now `db161057` (v3.50.0), two commits ahead (`9a8c8c43` #327, then the release). This head still contains `b4835511` and was not merged.

### Verdict

PASS. L-1900 is closed. No new finding. Next id 1902.

One commit, five files. Both hooks default to null. `Services` and the spark command do not pass one. With null, `afterWrites` runs an empty list and the three deletes stay in the same order inside the unit.

`in-unit-end` runs from `flush()` after the writes. On a connection, and on a PostgreSQL batch, another connection still sees the admins and the claim there, and sees them gone only after `run()` returns. A SQLite batch has already applied those writes by then. A claim `DELETE` immediately before `units->run`, and one immediately after, each fails the same-unit test on SQLite and PostgreSQL, both modes, and the PostgreSQL lock test. A delete before the `before` hook fails the same way. Dropping the session delete or widening the match still fails on both drivers. Dropping the lock still fails both PostgreSQL lock tests.

The five new-test cases, 10/10: OK, 78 assertions. The class once: OK, 22 tests, 247 assertions.

`testAnEntryOverTheFileSizeLimitLeavesNothing` (its `big.zip` under the file-size limit) passed here, 14 assertions. The known local failure is still `BackupHelpersTest`: expected "The backup is too large to unpack.", got "That file is not a zip archive." This commit does not touch that test.

CI, not waited on: Analyze (actions), Analyze (javascript-typescript), and CodeQL pass. No other jobs were listed yet.

Scratch PostgreSQL was stopped with `pg_ctl -D` and its data directory removed.

## Merge-only read at bb36de79

Live `refs/pull/318/head` is `bb36de799c25865f04c99567d97d8f048606c701` and main is `386bcb56e61eb981c9c5c824cb6e9aa6e0f13cca` (`ls-remote`). Parents are `b726d19a` and `386bcb56`. 0 behind main, `b726d19a` is an ancestor, and there are no non-merge commits since `b726d19a` outside main.

Own diff without CHANGELOG and `docs/` (index lines dropped, hunk headers normalised): `b4835511..b726d19a` and `386bcb56..bb36de79` are byte-equal, 891 lines. `docs/operator-console.md` is equal too. `docs/HANDOFF.md` updates the latest release to v3.51.0 and adds the #327 and #329 notes from main; `docs/history/handoff-log.md` adds the same. No code changed in the merge.

CHANGELOG: preamble and every released section equal main. Unreleased has only the edge and auth #318 bullets. README and VERSION equal main.

CI as it stood (`gh pr checks`): 10 pass, 12 pending.
