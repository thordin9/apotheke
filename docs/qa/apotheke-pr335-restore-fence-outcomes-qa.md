# PR #335 restore fence outcomes QA

Head `c87ff4cb6abd14f53c2ef2da7c55f7a299587551` (live `refs/pull/335/head` at the verdict). Main `a3689d892794e3cf6bf7546b3ce3ede543a53f7d` is an ancestor, 0 behind. This reissues the gate at `cc52fc7b`. Added commits: merge `eafe73fd` of `#334`, and `c87ff4cb`.

## Checklist

1. The own diff versus main is the same seven files as at `cc52fc7b`, plus the lines in `c87ff4cb`. `#334` is not in that diff. CHANGELOG preamble and every released section equal main. Unreleased matches main's `#334` notes. README and VERSION match main. `docs/HANDOFF.md` still moves the latest-release line from v3.53.1 to v3.53.2 and adds the fence note.
2. The hook still runs only when `getenv('APOTHEKE_RESTORE_FENCE_WAITING')` is a non-empty string. Unset and empty create no file and do not run the hook's `PRAGMA busy_timeout` read. On SQLite the file receives the live timeout (`60000` after the raise). On PostgreSQL there is no SQLite handle, so the file receives `waiting` and no pragma is read. `querySingle` is not reached unless the variable is set. A false result would be written as an empty string; a throw would skip the fence transaction after `finally` restores the timeout. Neither happens when the variable is unset.
3. L-1912 is closed. Removing the `busyTimeout` raise fails the SQLite case of `testAClaimWaitsPastTheUsualSqliteTimeout`. Removing the restore in `finally` fails that same case, which now reads `busy_timeout` on the claimer's connection after `claim()` returns.
4. `RestoreFenceTest`, 20/20: OK, 7 tests, 76 assertions, both drivers. The Backup suite once: the known zip-entry failure only.
5. N-2151 stays open. It is QA 2's alias for N-2147 in `docs/qa/apotheke-pr289-privacy-session-cookie-qa.md` and is handled by `#336`, not by this PR.

## Findings

L-1912 is closed on this head. N-2151 is out of scope. No new finding. Next id 1913.

## Security

With the variable unset or empty, the new read and the file write do not run. A request still cannot set the name `getenv` reads. When the variable is set, the value is still the path and the bytes are the live timeout on SQLite, or `waiting` on PostgreSQL.

## Verdict

PASS at `c87ff4cb`. No high or medium finding. L-1912 closed. N-2151 left open for `#336`.

Squash subject, 66 characters: `test(backup): check fence claims by outcome, not wall-clock (#335)`.

CI on this head, as it stood: Lint, deptrac, DI, D1, CodeQL, secret scan, conventional commits, Headless mobile, both Compose jobs, and both upgrade jobs pass. PHP sqlite and pgsql, both Docker builds, and the three visual shards are pending.
