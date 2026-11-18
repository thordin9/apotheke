# PR #317 re-gate evidence

Head `58135eee24536d1b7e87769a30c3450a0766f575`. `git ls-remote origin refs/pull/317/head` matched. Parent `9310bab1`. Diff: `docs/HANDOFF.md`, `docs/operator-console.md`, `modules/Auth/FirstAdminClaim.php`, `modules/Auth/UserRepository.php`, `tests/Modules/Auth/FirstAdminClaimTest.php` (+147/−18). `CHANGELOG.md`, `README.md` and `VERSION` are not in this commit. Against `85c6ad8c`, changelog is still the three Unreleased bullets. Mutations were restored (`git diff` empty).

## Fail-safe

`ensureFirstAdmin(true, …)` on an empty PostgreSQL database inserted `admin` / `admin` / `active`. A valid claim then returned 410, “This claim link is no longer valid.” The claim row count was 0. A wrong token returned 403, “This claim link is not valid. Ask for a new one.” A second use of the valid token returned 403. The only account was `admin`.

`FirstAdminClaimTest`: OK, 36 tests, 291 assertions, both drivers.

## Races

`testParallelUsesOnPostgresMakeOneAdmin` and `testParallelAdminAndClaimOnPostgresMakeOneAdmin`, connection and batch: 20/20 exit 0, each OK (4 tests, 28 assertions).

Lock integers on `users`: `active-admins` is `[1103712513, 1177423130]`, `plan-limit:users` is `[1103712513, 765199053]`, the empty key is `[1103712513, 0]`. The items plan key uses a different table id. Sorted key order is `active-admins`, then `plan-limit:users`.

## Who takes `active-admins`

`UserRepository::createIn` adds it when the new row is `admin` and `active` and the call opens the unit. Callers: `create`, `ensureFirstAdmin`, `UserAdmin::create`. `approve`, `setRole` and `delete` each run under that key. `RoleAdmin::delete` holds the guard; `RoleRepository::delete` then joins that open unit, so a move onto `admin` stays inside it. The claim passes the same key into its unit. `createIn` from the claim joins that unit rather than taking a second lock.

Import does not insert users. `Backups::tables()` does not list `users`. `Accounts::registerRole` cannot be `admin`.

## Active

`testOnlyAnActiveAdminStopsTheClaim` passed: a pending admin and an active viewer do not stop the claim. Account status in the app is `active` or `pending`.

## Mutations

| Change | Result |
| --- | --- |
| Remove the `NOT EXISTS` assert | exit 1. The three admin-present tests fail on sqlite and pgsql, including both parallel modes. |
| Count admins before `run()` and do not assert inside | exit 1. `testAnAdminAddedBeforeTheClaimRunsStopsIt` (sqlite and pgsql, both modes) and both parallel modes. |
| `createIn` does not add `active-admins` | exit 1. `testAddingAnActiveAdminTakesTheClaimsKeyOnPostgres`. |
| Assert before the delete, both still in the locked unit | exit 0. OK, 14 tests, 100 assertions. |
| Admin-exists error uses 200 | exit 1. The same admin-present tests as the dropped assert. |

## Suites

SQLite root: 639 tests, 10435 assertions, 1 failure (the module-suite wrapper). Module: 1465 tests, 47337 assertions, 2 failures: zip-entry and CMYK.

PostgreSQL root: 639 tests, 11210 assertions, the wrapper failure. Module: 1984 tests, 54213 assertions, the same two failures. No deadlock retry failure.

Edge `r2.test.mjs`: 118 pass, 0 fail. D1 workerd harness: OK, 323 tests, 1237 assertions.

CI on `58135eee`: Analyze (actions), Analyze (javascript-typescript) and CodeQL are success. No other check is attached yet.

Scratch PostgreSQL was stopped with `pg_ctl -D` and the data directory removed. Harness `node_modules` and its result cache were removed.

## Delta gate at dc1d232b (PASS)

Since the PASS at `58135eee`, the only new product commits are `e13b90be` and `84c59413`. Merges `da2d5178` (main `28373bcd`) and `dc1d232b` (main `6afe9cb9`) are not clean `merge-tree` results: they differ by conflict-marker removal in `CHANGELOG.md`, and the earlier merge also in `docs/operator-console.md` and `edge/tests/r2.test.mjs`. Each resolution keeps both sides (Unreleased C1 bullets plus the release/maintenance text, the hosted section after main’s lifecycle text, and both `lifecycle.test.mjs` and `bridge-reads.test.mjs`). No other product code.

`PlanLimits::fromBridge` ceils a finite float soft that is `>= 0`. Hard values must stay integers. Checked: `0` and `-0.0` store 0; negative, NaN, INF, strings, bools and arrays return null (the bridge reader then 503s); null or missing soft stores no soft; soft greater than hard is kept; a fractional hard returns null. A float soft of `1e20` is not rejected: `(int)ceil` stores `7766279631452241920` (N-1895). It is not treated as unlimited.

The JSON round trip of `hard * 0.8` for odd hards 1..21, and for `1572864`, stores the same integer as `Math.ceil` of that JavaScript value. The Worker still compares an integer counter with the unceiled float; the first integer that passes is that ceil. PHP does not invent the 80% itself. The live bridge fills it before PHP reads the body.

`GET /limits` still goes through `handleBridgeRead` (GET only, the object’s own `do_name`). `PLAN_LIMITS_JSON_SQL` is the same subquery `route.ts` already used, now shared. Keys without a hard value are omitted. A null soft becomes `hard * 0.8`. The `{ok, plan, limits:{key:{soft, hard}}}` shape is unchanged. Against `58135eee`, the same fixture changes `users.soft` from null to `2.4` when hard is 3. `parseLimits` of bad JSON returns `{}`, and the bridge still answers 200 with empty limits.

Mutations, each failing a test: floor instead of ceil; truncating a fractional hard; dropping the int check (`testAFractionalSoftValueFromTheBridgeIsRoundedUp`). Dropping the 80% default fails the E5 route test and `GET /limits`. Wrapping the limits object, or returning the raw SQL JSON, fails `GET /limits`.

Edge suite: 152 pass. D1 workerd: 323 tests, 1237 assertions. PHP: sqlite root 639/10397 and module 1466/46513; pgsql root 639/11175 and module 1985/53389. The only failure is the known zip-entry test. CMYK did not run (no Imagick). No deadlock flake.

CI on `dc1d232b`: Analyze (actions), Analyze (javascript-typescript), CodeQL, all success. PHP, D1, Docker and visual jobs are not attached.

N-1895. A finite float soft above the PHP integer range is cast and stored, not rejected. Ordinary 80% values are unaffected.

## Merge-only read at 307f40f3

`ls-remote` #317 `307f40f39e0bef52086b585f7189bc80c24aa4f0`, parents `5bd3657a` and main `7507025b`, 0 behind. `dc1d232b` is an ancestor. No non-merge commits since it outside main. `merge-tree` of `dc1d232b` and `7507025b` conflicts only in `CHANGELOG.md`. Own diff (excluding CHANGELOG.md and docs/qa, index and @@ lines dropped), `6afe9cb9..dc1d232b` against `7507025b..307f40f3`: 2869 lines each, one differing context line (`import { prefixBytes, selectedBucket, storageSelected, type R2Env } from "./r2";`, widened on main by #322). Changelog preamble and released sections equal main. README and VERSION equal main. CI as above.
