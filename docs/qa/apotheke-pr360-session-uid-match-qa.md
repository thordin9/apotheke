# PR #360 session uid match QA

Head `5074d34b0f4204e59983cf683df2faffc80ef8b2`, the merge of main `98135012a5115a07eb7d57745495e2de3112cc5a` (`#343`) into `4338c2fd`, 0 behind. The full gate ran at `7f457a73f09586293fbcee07e45b915650f0feaf` on main `4f28784d`; the merge-only read at `5074d34b` found its own diff unchanged.

## Checklist

1. `SessionUidMatch::condition()` is the only `uid|i:` pattern under `modules/`. The reset SQL and the disable SQL it builds are byte-identical to main (260 and 174 bytes). Disable bindings stay `[id, id, id]`. The three anchors remain: the field opens the payload, or follows `;`, or follows `}`. The claim delete, `DELETE FROM users WHERE role = 'admin'`, the hosted-only refusal, and `CurrentUser`'s disabled check are not in this diff.
2. The helper is `final` in `Apotheke\Auth`, with `condition()` and `bindings()`. Deptrac reports 0 violations. `HostedDisabledUsers` passes `?` and `bindings()`. `HostedAdminReset` passes the column `id` and no bindings.
3. `HostedAdminResetTest`, `HostedDisabledUsersTest`, and `SessionUidMatchTest`: 51 tests, 565 assertions, SQLite and PostgreSQL. The new test keeps `otheruid|i:1;`, a later `otheruid`, and `uid|i:11;` when the id is 1, and matches the three real shapes. The column form matches `uid|i:11;` for id 11 and not the id-1 rows. Restoring `%uid|i:`, dropping the semicolon, or dropping the `}` pattern fails both callers' anchored tests (8 tests: both classes, both drivers, both modes).
4. Merge-only read at `5074d34b`: parents `4338c2fd` and `98135012`, no other non-merge commit. Its code, test and hand-off diff against main equals the gated diff, excluding docs/qa. Main brought only edge files and a docs/qa file.
5. CHANGELOG, README, and VERSION have no diff. Main is 3.67.0. The hand-off sets the latest release to v3.67.0 and replaces the merged `#349` open-PR line with `#360`. `#349` is merged.

## Findings

N-1922 is closed. The predicate now has one definition. No new finding. Next id 1923.

## Security

The delete statements sent for a reset and for a disable are the same strings main sends, with the same bindings. An integer id still cannot introduce `%` or `_`. A viewer session is still outside the reset, because the reset still requires `role = 'admin'`. A flagged user is still refused by `CurrentUser`, which this PR does not change.

## Verdict

PASS at `5074d34b` (full gate at `7f457a73`, merge-only read at `5074d34b`). No high or medium finding. N-1922 closed.

CI on `7f457a73`, as it stood (the merge head was not yet pushed at this read): Analyze, CodeQL, conventional commits, D1, DI, both Compose jobs, Headless mobile, Lint, secret scan, deptrac, and both upgrade jobs pass. Both Docker builds, PHP sqlite and pgsql, and the three visual shards are pending.
