# PR #317 operator console C1 QA

Head `307f40f39e0bef52086b585f7189bc80c24aa4f0` (live), 0 behind main `7507025b` (v3.46.2). Own diff 37 files, +2110/−43. Gated: full gate at `9310bab1`, M-1889 re-gate at `58135eee`, the delta `e13b90be` plus `84c59413` at `dc1d232b`, then a merge-only read at this head.

## Checklist

1. M-1889 is closed. With an active admin, a valid claim returns 410, adds nobody, and deletes the claim row. `ensureFirstAdmin` with `APP_PASSWORD` still creates `admin` when the user table is empty; the claim is then refused and the row is gone. One admin remains. L-1891: a wrong token is 403 and the row stays.
2. PostgreSQL races, 20/20: claim against another claim, and claim against an admin insert that holds `active-admins`. Each run is 4 tests, 28 assertions. One active admin. The same key is taken by creating an active admin (`create`, including the `APP_PASSWORD` seed and `UserAdmin::create`), `approve`, `setRole`, user delete, and `RoleAdmin::delete` (it holds the guard; the role write joins that unit). Import does not create users. Backup tables do not include `users`. Registration cannot assign `admin`.
3. The delete, the `NOT EXISTS` active-admin assert, and the insert are one unit: one transaction on SQLite and PostgreSQL, one batch on D1. A failed assert rolls the unit back; a follow-up unit then removes the claim row. Batch-mode tests see the row gone.
4. Active means `role = admin` and `status = active`. A pending admin and an active viewer do not block the claim. There is no separate disabled status. A pending admin plus a claim creates a new active admin and leaves the pending row. That matches “only while no active admin exists.”
5. `active-admins` is not the plan-limit key or the empty unit key, and not another table’s plan key. `normalizeKeys` sorts, so a unit that takes both locks takes `active-admins` before `plan-limit:users`. Claim and admin create pass both keys into one `run()`.
6. Mutations: dropping the assert, checking before the unit, dropping the lock on admin create, and returning 200 instead of 410 each fail a test. Moving the assert above the delete but keeping both in the locked unit still passes (14 tests); that order does not open the race.
7. Limits delta (`dc1d232b`). PHP rounds a finite non-negative float soft up. A fractional hard, or a negative, NaN, infinite or non-numeric value, makes the body invalid and the reader returns 503. A null soft invents no limit in PHP. Bridge `/limits` reads `PLAN_LIMITS_JSON_SQL` through `parseLimits`, with soft defaulting to 80% of hard. It matches `Math.ceil` for odd hards 1 to 21 and for 1572864. It is still GET-only on the object's own name, and the `{ok, plan, limits}` shape is unchanged. Floor instead of ceil, a fractional hard accepted, dropped validation, a dropped 80% default, a changed shape and a skipped `parseLimits` each fail a test. Edge 152 pass. D1 workerd 323/1237.
8. Merge-only read at `307f40f3`. No commits other than merges since `dc1d232b`. Only `CHANGELOG.md` conflicted. Own diff equals the `dc1d232b` diff (2869 lines) except one context import line from main's #322. The changelog preamble and released sections equal main. Unreleased holds the three #317 bullets. README and VERSION equal main.
9. N-1890 is now written in `docs/operator-console.md`: the bridge reads follow storage mode, not `APOTHEKE_HOSTED`. Changelog, README and VERSION are unchanged by this commit. Released sections still match the parent. Suites fail only the known zip-entry and CMYK cases. No deadlock flake.

## Findings

M-1889 closed.

L-1891. While an admin exists, a wrong token returns 403 and leaves the row. A correct token returns 410, “This claim link is no longer valid.”, and the row is removed. A second try is then 403. No account is created.

N-1890 stays as documented behavior, not a new defect.

N-1895. A float soft above the PHP integer range (for example `1e20`) is cast and stored as `7766279631452241920`, not rejected. It is not treated as unlimited, and ordinary 80% values are unaffected.

Next id 1896.

## Security

The claim and every app path that adds or activates an admin share `active-admins`, and the empty-admin check is in the claim’s unit. Seeding `APP_PASSWORD` makes the claim fail closed. The remaining difference is the 403/410 pair in L-1891. Malformed bridge limits fail closed (503).

## Verdict

PASS at `307f40f3`. M-1889 is closed. One low finding (L-1891), notes N-1890 and N-1895.

CI on this head so far: Analyze, CodeQL, D1, Lint, deptrac, DI rule, Secret scan, Conventional Commits and both Upgrade and rollback jobs pass. Tests (sqlite, pgsql), Docker, Headless mobile, Visual E2E and the API dry run are pending. Tests must be green before merge.
