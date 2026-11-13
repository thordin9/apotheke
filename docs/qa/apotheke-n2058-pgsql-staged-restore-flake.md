# N-2058: StagedRestoreTest preflight case flakes on pgsql in CI

Severity **N** · Opened 2026-11-12 (EET) by DevOps from the #297 CI · Status: **open**, seen once, passed on re-run.

## Symptom
`Tests\Backup\StagedRestoreTest::testThePreflightRefusesBeforeAnythingIsStaged` with data set `"pgsql batch"`
(`'pgsql', true`) failed with `over the limit` at `tests/Modules/Backup/StagedRestoreTest.php:110`: the restore
was not refused with `RestoreStage::TOO_LARGE` (413) when the limit was set to the live size. It surfaced through
`Ci4ModuleSuiteTest::testCi4ModuleSuitePasses` (`tests/Unit/Ci4ModuleSuiteTest.php:26`).

## Observed
- CI run 43279194192, attempt 1, job `Tests (PHP 8.4, pgsql)`, PR #297 head e56bf994 (20:36 EET). The module
  suite reported `Tests: 1824, Failures: 1`. Re-running only the failed job passed, at the same head.
- The same test passed at the previous #297 head (fe8d8613) and in the local runs. #297 changes only config, docs
  and string assertions, nothing in backup or restore.

## Hypothesis
The test sets the limit to `RestoreStage::liveBytes()` read just before the restore. On PostgreSQL that is
`pg_database_size(current_database())`. The preflight (`modules/Backup/RestoreStage.php:106-110`) refuses when
`max(live, staged) + staged > limit`. It can only pass if the database shrank by at least the staged size between
the two reads. That can happen when other work on the same CI database drops tables or frees files in between, or
when pgsql frees space on its own in between (for example a vacuum).

## Next steps
- Make the case independent of a moving `pg_database_size`. Pass a fixed `liveBytes` into the preflight, or set the
  limit below the staged size, so that a shrinking database cannot make it pass.
- Track further occurrences by run id here.
