# PR #326 evidence

Final head `bcaee6351cb318fec32b94b50f797663f75382b7`. Full gate at `1114c679e2ad8cda960e3b8c8f8f3fa39bcf5b48`: `git ls-remote origin refs/pull/326/head` matched before that verdict. Base `184d4553`. README and VERSION are not in the diff. Mutations were restored.

## Limits

`testAnOutOfRangeOrNonFiniteLimitIsRefused` covers `1e20`, `9.2233720368547758e18`, INF, NAN, negatives and a huge JSON number, for soft and hard, and expects null then 503. Removing `ceil($n) < INT_LIMIT` fails that test.

## Claim and seeding

`testWithAnAdminAnyTokenGetsTheSameRefusal` (sqlite and pgsql, both modes) expects 410 and the same message for a right, reversed and empty token, and an empty claim row. Removing `dropAfterAdmin()` fails those four. `testHostedAppPasswordDecidesTheFirstAdminPath`: empty `APP_PASSWORD` migrates with sign-in on and no users, then the claim creates `owner`; a set password seeds `admin` and `password_verify` matches, and the claim is 410 with nobody added. `testHostedAppPasswordIsOptional`: auth status exit 0 and `on` with no password and no users; short password exit 4; not hosted with no password is exit 3 `off`. `ensureFirstAdmin` returns when `users->count() > 0`. Removing that clause errors `testTheFirstAdmin` on sqlite and pgsql (2 errors).

`signInEnabled()` is true whenever hosted. `registrationOpen()` is false while hosted and the account count is 0.

## Dump

`testTheDumpIsOneSnapshot` inserts a location and an item from a second connection after the first table. The zip has `Lamp` and not `Attic` or `Late`; the database has both. The next dump sees two items and the connection still writes. Setting the snapshot flag false errors that test on sqlite and pgsql. D1 `beginSnapshot()` returns false. The Installation object runs one request at a time.

## Overlap and orphans

`GUARD_SECONDS` is 600 and `STALE_SECONDS` is 7200. `testABackupStopsAtItsDeadline`: at 11:50:00 the job is 500 `backup_failed`, the claim is gone and no part remains; at 11:49:59 it is created. Changing that throw to 409 fails the test. `testATakenOverBackupStopsBeforeItWrites` expects 409 and no writes. Changing the lost-claim throw to 500 fails it. `testATakenOverBackupKeepsNoManifest`: a takeover before the manifest leaves no manifest; after the manifest, `discard()` removes it.

`ORPHAN_SECONDS` is 86400. At 2026-06-02 12:00:00, id `20260601-120000` is deleted and `20260601-120001` is kept. A folder with a manifest is kept, including `{not a manifest`. The export is kept. Setting the constant to 0 fails the test on local disk and object storage. 7200 is less than 86400.

## Hosted flag

`ApothekeConfig::load` for `1`, `true`, `TRUE`, `on`, `ON`, `yes`, `YES`, ` yes`, `yes ` and ` yes ` is true. `0`, `false`, `off`, `no`, empty, `maybe` and `2` are false. Requiring the string `1` only fails `testHostedIsOffUnlessSet`.

## Suites

Targeted module tests: OK, 139 tests, 1594 assertions. SQLite root: 641 tests, 10472 assertions, 1 failure (the module-suite wrapper). Module: 1502 tests, 46891 assertions, 1 failure, 91 skipped. PostgreSQL root: 641 tests, 11242 assertions, the wrapper failure. Module: 2038 tests, 53946 assertions, 1 failure, 66 skipped. The failure is `BackupHelpersTest::testAZipIsRefusedOnItsEntriesBeforeItsContents`. No deadlock retry failure. CMYK did not run.

CI on `1114c679`: Lint, deptrac, DI, D1, PHP sqlite, PHP pgsql, Docker, visual, upgrade/rollback and CodeQL success. Deploy skipped.

Scratch PostgreSQL was stopped with `pg_ctl -D` and the data directory removed.

## Delta at `ab8cce58`

Live head `ab8cce585d9c5d533a8de287447fc8fd38840ed5` matches `ls-remote`. Last PASS was `1114c679`. New commits: `c040e620`, `a7f0fbe3`, and merge `ab8cce58` of main `590cf9df`.

### Verdict

PASS. L-1896 is closed. No new finding. Next id 1897.

### Claim-create

`create()` generates the token, then in one `users` unit keyed by `active-admins` asserts `NOT EXISTS` an `admin` with status `active`, and only then replaces the claim row. A failure rolls the unit back, so an older claim row is unchanged and no new row is written. The command catches that 409, prints `An active admin already exists, so no claim was made.` on stderr, prints nothing on stdout, and exits 1. The token is not stored and not printed.

A pending admin and an active viewer do not count. The account API refuses to delete or demote the last active admin, so removing one admin does not open `claim-create` while another active admin remains. With no active admin, `create()` still issues a claim and the claim still makes the first admin. With `APP_PASSWORD` set, the seeded `admin` makes `create()` return 409 and leave no claim row.

The check is inside the lock. `testCreateWaitsForAnAdminBeingAddedOnPostgres` holds that key, inserts an admin, and the waiter is refused: 10/10, connection and batch. Removing the assert fails that race and `testCreateIsRefusedWhileAnActiveAdminExists` on sqlite and pgsql, both modes. Moving the read to before `run()` fails the race on both modes.

`FirstAdminClaimTest` and `ClaimFormTest`: OK, 55 tests, 575 assertions. `testHostedAppPasswordIsOptional`: OK, 60 assertions.

### Changelog and merge

`c040e620` changes the Unreleased link from pull/000 to pull/326. Released sections of `CHANGELOG.md` equal main `590cf9df`.

`git merge-tree` of `a7f0fbe3` and `590cf9df` conflicts only in `CHANGELOG.md`. The merge keeps the #326 bullet and the `[3.48.1]` section. The own diff against `590cf9df`, excluding `CHANGELOG.md` and `docs/qa`, differs from the own diff of `1114c679` only by `a7f0fbe3` (the command, `create()`, and the docs that describe the refusal).

### CI

On `ab8cce58`, as it stands: Lint, deptrac, DI, D1, CodeQL, secret scan, conventional commits, one Docker Compose job, and both upgrade jobs pass. PHP sqlite and pgsql, the three visual shards, and the other Docker jobs are still in progress. Deploy is skipped.

Scratch PostgreSQL was stopped with `pg_ctl -D` and its data directory removed.

## Merge-only read at `bcaee635`

`ls-remote` gives `bcaee635` for pull/326 and `77131ed8` for main. Parents `5dfb7cc5` and `77131ed8`, 0 behind. `ab8cce58` is an ancestor, and `git log --no-merges ab8cce58..bcaee635 ^origin/main` is empty. The own diff excluding `CHANGELOG.md` and `docs/qa`, with index and hunk lines dropped, is 1677 lines and matches `590cf9df..ab8cce58` under `cmp`. The CHANGELOG preamble and released sections equal main. README and VERSION equal main. `gh pr checks 326`: 22 pass, 1 skipping.
