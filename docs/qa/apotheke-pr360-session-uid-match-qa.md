# PR #360 session uid match QA

Head `7f457a73f09586293fbcee07e45b915650f0feaf` (live `refs/pull/360/head` at the verdict). Main `4f28784d195d6452ce4253ff9fc588307723ebfb` is an ancestor, 0 behind.

## Checklist

1. `SessionUidMatch::condition()` is the only `uid|i:` pattern under `modules/`. The reset SQL and the disable SQL it builds are byte-identical to main (260 and 174 bytes). Disable bindings stay `[id, id, id]`. The three anchors remain: the field opens the payload, or follows `;`, or follows `}`. The claim delete, `DELETE FROM users WHERE role = 'admin'`, the hosted-only refusal, and `CurrentUser`'s disabled check are not in this diff.
2. The helper is `final` in `Apotheke\Auth`, with `condition()` and `bindings()`. Deptrac reports 0 violations. `HostedDisabledUsers` passes `?` and `bindings()`. `HostedAdminReset` passes the column `id` and no bindings.
3. `HostedAdminResetTest`, `HostedDisabledUsersTest`, and `SessionUidMatchTest`: 51 tests, 565 assertions, SQLite and PostgreSQL. The new test keeps `otheruid|i:1;`, a later `otheruid`, and `uid|i:11;` when the id is 1, and matches the three real shapes. The column form matches `uid|i:11;` for id 11 and not the id-1 rows. Restoring `%uid|i:`, dropping the semicolon, or dropping the `}` pattern fails both callers' anchored tests (8 tests: both classes, both drivers, both modes).
4. CHANGELOG, README, and VERSION have no diff. Main is 3.67.0. The hand-off sets the latest release to v3.67.0 and replaces the merged `#349` open-PR line with `#360`. `#349` is merged.

## Findings

N-1922 is closed. The predicate now has one definition. No new finding. Next id 1923.

## Security

The delete statements sent for a reset and for a disable are the same strings main sends, with the same bindings. An integer id still cannot introduce `%` or `_`. A viewer session is still outside the reset, because the reset still requires `role = 'admin'`. A flagged user is still refused by `CurrentUser`, which this PR does not change.

## Verdict

PASS at `7f457a73`. No high or medium finding. N-1922 closed.

CI on this head, as it stood: Analyze, CodeQL, conventional commits, D1, DI, both Compose jobs, Headless mobile, Lint, secret scan, deptrac, and both upgrade jobs pass. Both Docker builds, PHP sqlite and pgsql, and the three visual shards are pending.
