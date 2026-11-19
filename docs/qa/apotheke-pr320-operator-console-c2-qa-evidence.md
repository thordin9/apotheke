# PR #320 evidence

Head `c0bbc7ce0b4b5e650e6ed4f1c428b15469fca476`. `git ls-remote origin refs/pull/320/head` matched. Ancestor of `58135eee`: yes. Diff vs that commit: 18 files, +1074/−9. README and VERSION match. Changelog adds one Unreleased bullet whose link is pull/318. `gh pr view 318` is “feat(edge): hosted env for installations other than primary”, state open. This PR is 320. Mutations were restored.

## Reachability

`edge/src/index.ts` `toContainer` sets `X-Forwarded-Proto` and `X-Forwarded-Host` on every public container fetch, and sets `X-Apotheke-Client-IP` when `cf-connecting-ip` is present. It does not copy a client `X-Forwarded-For`. `OpsBackupEndpoint::applies` returns false if any of the four names is present, compared after `array_change_key_case`. `opsBackupRequest` builds `http://ops.internal/internal/backup` with no forwarded headers and is not called from the public fetch. The filter `opsbackup` is the first required before-filter. A non-matching request returns null and is not this endpoint.

`OpsBackupsTest` + `OpsBackupHttpTest` + `StoredBackupsTest`: OK, 39 tests, 309 assertions, both drivers.

## Signature

`verify` regex is `^t=(\d{1,12}),v1=([0-9a-f]{64})$` after trim, then `abs(now-t) > 300`, then `hash_equals`. `fromAppKey` uses `SecretKey::bytes` (trim, optional `base64:`, strict base64, length 32) and returns null otherwise. `Services::opsBackupEndpoint` passes that null through, and `handle` answers 503 `not_configured`. `error_log` records `get_class` only. Shared vector file is read by `testTheSignatureMatchesTheVectorTheEdgeChecks` and `edge/tests/ops-signature.test.mjs`. Edge `r2.test.mjs`: 120 pass, 0 fail.

Body length is checked with `||` before `verify`, so a body over 4096 bytes is not hashed. `getBody()` runs only after `applies()` is true.

## Idempotency

`testParallelRequestsOnPostgresMakeOneBackup`, connection and batch: 15/15 exit 0, each OK (2 tests, 8 assertions). The other client holds `lockKeys('settings', 'ops_backup_job:job-1')` and inserts a running row. The waiter is 409 and writes no backup.

`claim` is one unit: `NOT EXISTS` then insert, or `UPDATE … WHERE value = ?` expect 1. `STALE_SECONDS` is 7200. The running check uses `<`. At 1 second under 2 hours the test expects 409; at 2 hours it takes over.

`testOneBackupPerJob` asserts the settings prefix is absent from `SettingsService::export()`.

## Manifest

`files()` requires `key === part-NNNNN`, a non-negative int size, a lowercase 64-hex sha256, one entry per part, and the sizes equal `size`. `testAManifestWithAWrongPartListIsNotRead` covers `../part-00001`, an absolute key, the full key, a renamed part, a bad size and an uppercase sha256. A manifest with no `files` is accepted and `bytes()` still reads `part-N`. `prune()` skips `kind === export`.

## Mutations

| Change | Result |
| --- | --- |
| `verify` returns true | `testTheEndpointRefusesARequestWithoutAValidSignature` fails |
| Window check removed | Same test fails (stale `t` accepted) |
| Host comparison removed | `testTheEndpointIsNotThereForAPublicHostAForwardedRequestOrSelfHosted` fails |
| `X-Forwarded-Host` removed from the list | Same test fails |
| Compare only the first 8 hex digits | Exit 0. The existing wrong-key cases still differ in those digits, so the suite does not catch that prefix |
| `claim()` not called | Exit 2. `testOneBackupPerJob` on sqlite and pgsql, both modes, and both parallel modes |
| `STALE_SECONDS = 0` | `testAJobAlreadyRunningIsInProgressUntilItIsStale` fails on sqlite and pgsql, both modes |
| Part-key equality removed | `testAManifestWithAWrongPartListIsNotRead` fails |
| Hosted flag ignored | The public-host test fails |
| `fromAppKey` HMACs even when the key bytes are null | `testTheEndpointRefusesARequestWithoutAValidSignature` fails |

## Suites

SQLite root: 639 tests, 10407 assertions, 1 failure (module-suite wrapper). Module: 1484 tests, 46671 assertions, 1 failure, 91 skipped. The failure is `BackupHelpersTest::testAZipIsRefusedOnItsEntriesBeforeItsContents`.

PostgreSQL root: 639 tests, 11181 assertions, the wrapper failure. Module: 2009 tests, 53571 assertions, the same zip-entry failure, 66 skipped. No deadlock retry failure. CMYK did not run; Imagick is not installed.

D1 workerd harness: OK, 323 tests, 1237 assertions.

CI on `c0bbc7ce`: Analyze (actions), Analyze (javascript-typescript) and CodeQL success. No other check is attached.

Scratch PostgreSQL was stopped with `pg_ctl -D` and the data directory removed. Harness `node_modules` and its result cache were removed.

## Delta gate at 9c73ed75 (PASS)

`c0fe6cd1` changes one changelog line, `#318` to `#320`. That closes L-1892. `git merge-tree` of `c0fe6cd1` and `dc1d232b` is the tree of `9c73ed75` (no conflict). The C2 diff against `dc1d232b` matches the C2 diff of `c0bbc7ce` against `58135eee` except that link, plus context where main added the lifecycle import and the hosted docs moved. No C2 code was re-resolved. N-1893 and N-1894 are unchanged.

CI on `9c73ed75`: the same three checks, all success. The rest are not attached.

## Merge-only read at 6c5060e1

`ls-remote` #320 `6c5060e1d4d3faca0b6900be5649a61f4df7a8bf`, parents `bbe0496d` and `307f40f3`, 0 behind main `7507025b`. `9c73ed75` and `307f40f3` are ancestors. `merge-tree` of `9c73ed75` and `307f40f3` is clean. Own diff `dc1d232b..9c73ed75` against `307f40f3..6c5060e1`: byte-equal, 1239 lines. Changelog preamble and released sections equal main. Unreleased adds the #320 backup bullet above the three #317 bullets. README and VERSION equal main.

## Merge-only read at b138a3c7

`ls-remote` #320 `b138a3c7e10992584f4cfa699f9622ac6b152dae`, parents `6c5060e1` and main `81b51e07` (v3.47.0), 0 behind. `6c5060e1` is an ancestor. No non-merge commits since it outside main. `merge-tree` of `6c5060e1` and `81b51e07` conflicts in `CHANGELOG.md`, `docs/HANDOFF.md`, `docs/operator-console.md` and `edge/tests/r2.test.mjs`. Own diff (excluding CHANGELOG.md, docs/qa and docs/HANDOFF.md, index and @@ lines dropped), `307f40f3..6c5060e1` against `81b51e07..b138a3c7`: byte-equal, 1226 lines. The `docs/HANDOFF.md` diffs are equal too. Changelog preamble and released sections equal main. Unreleased holds only `### Added` and the #320 bullet linking #320. README and VERSION equal main.
