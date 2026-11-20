# PR #344 evidence

Final head `0020f20781f35d2238a141b2d9b663843fe44f4f`, 0 behind main `8def0fd2b334e2007ba2e8c319ace3afe6277934`; see Delta and the Merge-only reads below. Full gate head `260c9813dce326e8401bdcb7c8fb387d8d9479bf`. Live `git ls-remote origin refs/pull/344/head` returned that SHA immediately before the verdict. `refs/heads/main` was `3a6156b6ada5c9ff27c92ecbfdfbc70588f42484`. `git rev-list --count HEAD..3a6156b6` was 2: `34706c47` (`#346`) and `3a6156b6` (v3.57.0). `c62dafd8` is an ancestor and the behind count against it is 0.

`git diff --name-only c62dafd8...HEAD`: `CHANGELOG.md`, `docs/HANDOFF.md`, `docs/operator-console.md`, `edge/migrations/control/0005_limit_overrides.sql`, `edge/src/limits.ts`, `edge/src/route.ts`, `edge/tests/bridge-reads.test.mjs`, `edge/tests/control-migrations.test.mjs`, `edge/tests/limits.test.mjs`. `route.ts` changes comments only. README and VERSION have no diff against `c62dafd8`. No PHP file, no workflow, and no `wrangler.jsonc` change. `rg limit_overrides` under `modules/`, `migrations/`, and `app/` has no match.

## Migration

Node v22.23.4, `node:sqlite`, `PRAGMA foreign_keys = ON`, files in `edge/migrations/control/` through `0004`, then `0005`. SHA256 of `0005` is `9764a050cdacfc34160f74c575b59e72944ff916eb30a89c91ccca8df95c4735`, the same value the pin test expects.

After seeding a plan, two installations, and subscriptions, then applying `0005`: plans, `plan_limits`, and subscriptions were unchanged. The only added table was `limit_overrides`. A row with `hard_value` 80 was still 80 after a second apply.

`DROP TABLE limit_overrides` left every other table's `sqlite_master` SQL equal to the pre-drop snapshot. Applying the file again recreated an empty table. There is no down migration. `wrangler d1 migrations apply` is forward only, and the deploy job runs it before `wrangler deploy`.

PostgreSQL 16, data dir `/tmp/apotheke-pg-344.jCLold`, port 54355, trust auth. `psql -f edge/migrations/control/0005_limit_overrides.sql` exited 3: `function strftime(unknown, unknown) does not exist` at the `created_at` default. The table was not created. Stopped with `pg_ctl -D` that dir `-w stop`, then the directory was removed.

The installation Durable Object database is not given this file. `/limits` reads the control D1 binding.

## Values

Shared SQL, plan `users` hard 3 and `items` soft 40 hard 50. A row for installation `a` only:

- `0` stored as integer. JSON `users: [null, 0]`. `parseLimits` keeps `{soft: 0, hard: 0}`.
- `1` stored as integer. JSON `users: [null, 1]`. `parseLimits` keeps hard 1, soft 0.8.
- `'abc'` and `''` stored as text. JSON has a string hard. `parseLimits` returns `{}` for that object, so the plan cap is omitted.
- `1.5` stored as real and kept.
- `-1` rejected, `CHECK constraint failed: hard_value >= 0`. NULL hard rejected, `NOT NULL`.
- Installation `b`'s `items` row of 7 changed `b` only. `a` and `primary` stayed on the plan. A `primary` row of `items` 1 did not change `primary`.
- `expires_at` `''` left `users` at the plan hard 3. `not-a-date` applied hard 9. NULL applied hard 9.

`parseLimits` was called with the JSON strings, not objects. A non-string argument is ignored by the existing function and returns `{}`.

## Tests and mutations

`node --test --experimental-strip-types edge/tests/r2.test.mjs`: `tests 221`, `pass 221`, `fail 0`, `skipped 0`.

Each mutation was restored before the next. `git diff` was empty afterwards.

- Delete `o.installation_id = i.id`: `GET /limits applies a live override...` fails.
- Delete `i.do_name <> 'primary'`: that test and `primary does not apply them` fail.
- Delete the `expires_at` predicate: both the shared-SQL test and the `/limits` test fail.
- Replace `COALESCE(o.hard_value, l.hard_value)` with `l.hard_value`: the shared-SQL test fails.
- Remove `CHECK (hard_value >= 0)`: `0005 limit_overrides...` fails.
- `CREATE TABLE` without `IF NOT EXISTS`: that test fails on the second apply.
- Append `DROP TABLE plan_limits`: `control migrations are numbered wrangler-style and additive only` fails.

The text `hard_value` and the non-timestamp `expires_at` are not asserted.

## Changelog and CI

`CHANGELOG.md` on this head starts at `## [Unreleased]`. `c62dafd8` and main `3a6156b6` start with `# Changelog` and the Keep a Changelog paragraph. From `## [3.56.0]` this head equals `c62dafd8`. `release.php` still finds `## [Unreleased]`.

`gh pr checks 344` at the verdict listed only Analyze (actions) pass, Analyze (javascript-typescript) pass, and CodeQL pass. The rollup had those three, all `COMPLETED` / `SUCCESS`, for this SHA.

Subject `feat(edge): apply live limit_overrides in the /limits merge (#344)` is 66 characters.

## Delta at 2e077eb6

Head `2e077eb6f1266c7a57905bf417d31399cfdd0077` (live `refs/pull/344/head` at the verdict). Fast-forward from `260c9813`. Main `e0287d50f86c291c18be3dd9228aa7a1cb81b902` (v3.58.0) is 2 commits ahead (`#348` and the release). Not waited on.

### Checklist

1. Merge `0c5968e9` parents are `260c9813` and `8d28bf05`. The normalized own diff of `c62dafd8..260c9813` equals `8d28bf05..0c5968e9` with `CHANGELOG.md` and `docs/qa` excluded (18619 bytes both). The later commits are the HANDOFF release line, the changelog preamble, the integer check, and the expiry check.
2. L-1914 is closed. The changelog header before `## [Unreleased]` equals `8d28bf05`. From `## [3.57.0]` the file equals that main. Unreleased says a live row can override (raise or lower) and links `#344`. README and VERSION have no diff against `8d28bf05` or against current main.
3. L-1915 is closed. The CHECK is `typeof(hard_value) = 'integer' AND hard_value >= 0`. `'abc'`, `''`, `1.5`, `'1.5'`, and a blob are rejected. A forced text row is ignored by the reader and the plan cap stays. `0` and a lower integer are stored and applied. INTEGER affinity converts `'10'`, `' 10 '`, and `'1e2'` to integers 10, 10, and 100 before `typeof` runs (N-1920). Those are not stored as text.
4. L-1916 is closed for the reported values. `''`, `not-a-date`, a date-only value, and a lowercase `t` are rejected. Forced `not-a-date` is not applied. NULL is live. `2099-01-01T00:00:00Z` is applied. `2000-01-01T00:00:00Z` is stored and not applied. `9999-99-99T99:99:99Z` matches the shape and is applied (N-1921).
5. The join is still `o.installation_id = i.id` and `i.do_name <> 'primary'`. `LIMITS_ENABLED` in `edge/wrangler.jsonc` is still `"0"`. No file under `edge/src` inserts into `limit_overrides`.
6. Edge suite: 240 pass, 0 fail, 0 skipped. Removing `typeof(hard_value) = 'integer'` fails the `0005` test. Removing the reader `typeof` fails both non-integer tests. Removing the expiry shape from the reader fails both malformed-expiry tests. Each edit was restored. The mutation notes file was not on this machine.

### Findings

L-1914, L-1915, and L-1916 are closed.

N-1920. A numeric string that SQLite converts to an integer passes the CHECK and is applied. `'abc'` and other non-integers do not.

N-1921. A value that matches the timestamp shape but is not a real calendar time, such as `9999-99-99T99:99:99Z`, is treated as live. Next id 1922.

### Verdict

PASS at `2e077eb6`. No high or medium finding.

CI on this head, not waited on: only the CodeQL suite ran (Analyze actions, Analyze javascript-typescript, and CodeQL, all success). No Actions run of `CI`, `Deploy edge`, `Frontend`, or `Deploy frontend` exists for this SHA, and the branch `feat/limit-overrides` has no Actions runs. The same was true of `260c9813`. `ci.yml` has no path filter, so the missing PHP and Docker jobs are not a path-filter skip. `deploy-edge.yml` matches this diff (`edge/**`, not only `edge/app/**`), so its absence is not a path-filter skip either. `frontend.yml` and `deploy-frontend.yml` do not match the diff against current main. `ops-console.yml` does not match. `edge-local.yml` is manual. No `[skip ci]` trailer is on the head commit.

## Merge-only read at 52be3c46

`git ls-remote` gave `52be3c46` for `refs/pull/344/head` and `e0287d50` for main; behind count 0; `2e077eb6` is an ancestor. `de668d3f` parents: `2e077eb6`, `e0287d50`. `git log --no-merges 2e077eb6..52be3c46 ^origin/main` lists only `52be3c46` (docs: HANDOFF latest release v3.58.0). The normalized diff of `merge-base(2e077eb6, e0287d50)..2e077eb6` equals `e0287d50..52be3c46`, excluding CHANGELOG.md, docs/qa and docs/HANDOFF.md. CHANGELOG split at `## [Unreleased]`: the preamble and released sections equal main, and Unreleased adds only the #344 Added bullet. HANDOFF against main: the release line v3.53.2 to v3.58.0 and the #344 open-PR bullet. README and VERSION: no diff. `gh pr view`: MERGEABLE.

## Merge-only read at 0020f207

`git ls-remote` gave `0020f207` for `refs/pull/344/head` and `8def0fd2` (#341) for main; behind count 0; `c05dde55` is an ancestor. `0020f207` parents: `c05dde55`, `8def0fd2`. `git log --no-merges c05dde55..0020f207 ^origin/main` is empty. `git diff --stat c05dde55 0020f207` touches only `tests/e2e/package.json`, `tests/e2e/package-lock.json` and `docs/qa/apotheke-pr341-puppeteer-25170-qa.md`, the #341 files, all equal to main. The normalized diff of `merge-base(c05dde55, 8def0fd2)..c05dde55` equals `8def0fd2..0020f207`, excluding CHANGELOG.md and docs/qa. CHANGELOG: preamble and released sections equal main; Unreleased adds only the #344 Added bullet. HANDOFF against main: the release line v3.53.2 to v3.58.0 and the #344 bullet. README and VERSION: no diff. `gh pr view`: MERGEABLE, BLOCKED on required checks. CI as it stood: 12 pass, 10 pending (PHP sqlite and pgsql, Docker builds, Compose, edge check and dry run, visual shards), none failed.
