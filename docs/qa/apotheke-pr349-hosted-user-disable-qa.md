# PR #349 hosted user disable QA

Head `919a9f0977f3691fbfcc31445ccd48bc15c4497a` (live `refs/pull/349/head` at the verdict): merge `783cc8c4` of main `096663467bdd303323f2da4a2d1df6f04bcf1e14` (v3.66.0) plus a hand-off commit, 0 behind. Full gate at `61ef46f1`, delta at `6bc6b8d2`, merge-only reads since.

## Checklist

1. Eighteen files. `0035_hosted_disabled_users.php` adds `hosted_disabled_users` (`user_id`, `disabled_at`), no foreign key, same SQL for SQLite and PostgreSQL. `HostedDisabledUsers` flags one user, ends matching session rows, and refuses the last active admin. `Accounts::admit` and `CurrentUser` refuse a flagged user. `OpsAccountsEndpoint` adds signed `POST /internal/accounts/disable` and `/enable`. Services wires them only when hosted. Tests, `Backups::UNCARRIED_MIGRATIONS`, `upg_ci4_only()`, the upgrade script, and the console doc follow. `route` and the Worker client are unchanged.
2. The migration is CI4-only and listed in `UNCARRIED_MIGRATIONS` and `upg_ci4_only()` as `hosted_disabled_users`. It does not change `users`. Existing accounts stay enabled because the new table starts empty. There is no down SQL. A rollback leaves the table, which is the CI4-only rule. `testEveryCi4OnlyMigrationIsRegistered` and `testNoCi4OnlyMigrationChangesATable26Reads` pass on SQLite and PostgreSQL. CI's upgrade-and-rollback jobs on this head pass for both drivers.
3. Disable and a second disable flag the user and leave `users.status` active. Sign-in then gets 403. That user's session row is removed. A neighbour id and another user's session stay. `CurrentUser` treats a leftover session as signed out. Enable removes the flag and does not change the password, status, or username. The ended session is not put back. There is no API-token table. The last active admin, including when another admin is already disabled and when a pending admin exists, gets 409 and keeps the session. Twelve pairs of concurrent disables on SQLite and PostgreSQL, connection and batch, never left zero active admins. Disabling the last admin is that 409. Another admin can be disabled. An unknown id is 404. With hosted off, the flag is ignored.
4. At the full gate the new tests were 19 tests, 273 assertions, both drivers where the provider runs. Removing the last-admin condition, the sign-in check, the session check, or the missing-user check fails those tests on both drivers. Dropping `0035` from `UNCARRIED_MIGRATIONS`, or replacing the migration with `DROP TABLE users`, fails the migrate tests. Each edit was restored.
5. Delta at `6bc6b8d2`: `a10c5a25` deletes a session only when `uid|i:<id>;` opens the payload or follows `;` or `}`. `otheruid|i:<id>;` and `uid|i:<id>0;` stay. `HostedDisabledUsersTest`: 20 tests, 248 assertions, both drivers. Reverting to the old match or dropping the semicolon fails it.
6. Merge-only reads at `a4b2d461` and `919a9f09`: the code and test diff against main equals the gated diff, apart from index lines and hunk offsets. The changelog header and released sections equal main. Unreleased is one Added bullet linking `pull/349`. README and VERSION (3.66.0) have no diff. The hand-off sets v3.66.0 and replaces the `#355` line with this PR.

## Findings

L-1917 (closed at `6bc6b8d2`). `CHANGELOG.md` had lost the title and preamble, and Unreleased linked `pull/000`. Both are fixed.

N-1918 (closed at `6bc6b8d2`). The session delete matched `otheruid|i:<id>;` as well as `uid|i:<id>;`. It is now anchored on the `uid` key.

N-1919. `edge/src/ops-accounts.ts` still sends only claim and reset-admins. The container answers the new paths. The console route `POST /installations/:id/users/:uid/disable` stays in the deferred list. Next id 1923.

## Security

The filter answers these paths itself: no session and no CSRF. `applies` requires hosted, the host `ops.internal`, the exact path, and none of the forwarding headers the Worker sets on a public request. The method must be POST. The body must be exactly `{"user_id": <positive integer>}` and the signature must cover that body and path. A public host, a forwarded request, or hosted off is not this endpoint. The id is a user in this container's database. An id that is not there is 404, and the body has no installation field. Success and failure do not write an audit row. Claim and reset do not either. `isDisabled` returns false if the read throws, which is how a missing table is ignored.

## Verdict

PASS at `919a9f09`. No high or medium finding. L-1917 and N-1918 closed, N-1919 open.

Squash subject, 69 characters: `feat(auth): disable or re-enable a hosted user on ops.internal (#349)`.

CI on this head, as it stood and not waited on: Analyze (actions) and CodeQL pass. Every other check, including PHP sqlite and pgsql, is pending.
