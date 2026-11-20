# PR #344 limit overrides QA

Head `0020f20781f35d2238a141b2d9b663843fe44f4f` (live `refs/pull/344/head` at the verdict). Main `8def0fd2b334e2007ba2e8c319ace3afe6277934` (v3.58.0 plus #341) is an ancestor, 0 behind. Full gate at `260c9813`, a delta at `2e077eb6` (the fixes), then a merge-only read of `de668d3f` (merge of `e0287d50`), `52be3c46` (HANDOFF release line), and `0020f207` (merge of `8def0fd2` into doc commit `c05dde55`).

## Checklist

1. Nine files versus `c62dafd8`. `0005_limit_overrides.sql` adds `limit_overrides`. `limits.ts` left-joins a live row into the shared plan-limits SQL. `route.ts` is comments only; `/limits` and the route cache use that SQL. Three test files cover the merge, the table, and `LIMITS_ENABLED` off. `docs/operator-console.md` and `docs/HANDOFF.md` describe it. CHANGELOG adds only the Unreleased bullet; the preamble and released sections equal main.
2. On Node SQLite (the D1 stand-in) the file applies, applies again without changing the row, and leaves plans, limits, and subscriptions as they were. The only new table is `limit_overrides`. There is no down file. `DROP TABLE limit_overrides` restores the earlier tables and rows. PostgreSQL rejects the file (`strftime` does not exist). It is not a PHP migration, so `Backups::UNCARRIED_MIGRATIONS` and the app upgrade jobs are not involved. The deploy applies `edge/migrations/control/` before the worker and stops if apply fails. A worker running this SQL before the table exists makes the lookup throw; `routeFor` then uses the primary fallback, which carries no limits.
3. A live row changes only that installation. Another installation on the same plan, and `primary`, stay on the plan. An expired timestamp is ignored. No row, or a key the plan does not already cap, leaves the plan result. `hard_value` 0 and a smaller integer replace the plan hard. A non-integer `hard_value` (`abc`, `''`, `1.5`, a blob) is rejected by the CHECK and ignored by the reader. An empty, malformed, or date-only `expires_at` is rejected and, if forced, not applied. NULL means no expiry. With `LIMITS_ENABLED` not exactly `"1"`, the lookup is not issued. `edge/wrangler.jsonc` still has `"0"`.
4. Edge suite at `2e077eb6`: 240 pass, 0 fail. Undoing the integer CHECK, the reader guard, or the malformed-expiry rule fails a test. At `260c9813`: 221 pass, 0 fail, 0 skipped. Removing the installation match, the `primary` exclusion, the expiry predicate, or the override value fails a test. Removing `CHECK (hard_value >= 0)`, `IF NOT EXISTS`, or adding `DROP TABLE` fails a test. Each edit was restored.
5. `de668d3f` has parents `2e077eb6` and `e0287d50`. The own patch against main equals `2e077eb6`'s (CHANGELOG, docs/qa and HANDOFF excluded). `52be3c46` sets the HANDOFF latest release to v3.58.0 and keeps the #344 open-PR note. README and VERSION have no diff.
6. `0020f207` has parents `c05dde55` and `8def0fd2` and adds only #341's files (`tests/e2e/package.json`, `package-lock.json` and its docs/qa doc), equal to main. The own patch against main is unchanged; CHANGELOG preamble and released sections equal main.

## Findings

L-1914, L-1915, and L-1916 are closed at `2e077eb6`: the preamble is restored, the row is an integer override (raise or lower) in the CHECK, reader and docs, and a malformed `expires_at` is not applied.

N-1920. INTEGER affinity converts `'10'`, `' 10 '`, and `'1e2'` to 10, 10, and 100 before the type check, so those inserts store integers.

N-1921. `9999-99-99T99:99:99Z` matches the timestamp shape and is applied as a far-future expiry. Next id 1922.

## Security

Nothing in this diff writes a row. There is no new request path, form, import, or restore, and a tenant env does not set one. `/limits` still uses the object's own name. `primary` is excluded in the join. Limits and registrations are not switched on: `LIMITS_ENABLED` stays `"0"`, and this PR does not add a registration flag. The values in N-1920 and N-1921 matter only for a direct write to the control database. The console API that would grant a row is not in this PR.

## Verdict

PASS at `0020f207`. No high or medium finding. L-1914, L-1915, L-1916 closed. N-1920, N-1921.

Squash subject, 66 characters: `feat(edge): apply live limit_overrides in the /limits merge (#344)`.

CI on this head, as it stood: Analyze, CodeQL, conventional commits, D1, DI, Lint, deptrac, secret scan, both upgrade jobs, and Headless mobile pass. PHP sqlite and pgsql, both Docker builds, both Compose jobs, the edge check and dry run, and the three visual shards are pending. At `2e077eb6` only CodeQL ran, because the branch conflicted with main.
