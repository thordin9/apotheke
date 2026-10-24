# QA: apotheke #84, session lifetime (delta re-check)

- **PR:** [#84](https://github.com/thordin9/apotheke/pull/84), `fix(auth): bound session lifetime and sign out everywhere`
- **Head:** `e602418` (earlier PASS at `6d9d23d`). **Base:** `main` `9ad5b74` (v2.6.11). `e237104` after it only adds `docs/qa/apotheke-v2.6.11-release-qa.md` (byte-identical to the QA copy).
- **Delta:** `dffe9b8` (L-1), `36a6a10` (L-2), `da15c83` (N-1), `9e35514` (N-4), plus `ff6f45c` (merges v2.6.10) and `e602418` (merges v2.6.11).
- **Verdict: PASS.** 0 High, 0 Medium, 1 Low, 4 Nits open. Read-only clone, no API.

## Checklist

| Check | Result |
|---|---|
| CI | 10/10 at `e602418` with Visual E2E green, as reported by the PM. I couldn't check it myself because the GitHub API isn't reachable from here. |
| Tests at `e602418` | PHPUnit 476 tests (3 env skips) OK on PHP 8.4 and 8.3, SQLite and PostgreSQL 17. `tests/integration.php`: SQLite 1714/0 and PostgreSQL 1385/0 on both PHP versions. |
| Mutations | 5 of 6 caught (below) |
| Merges | `git show --remerge-diff` lists only `CHANGELOG.md` for both merges. Per-file changed lines `3f729a3..9e35514` = `32b1ef8..ff6f45c` = `9ad5b74..e602418` (same 24 files; the PR's 8 PNG blobs are unchanged and main doesn't touch them). |
| CHANGELOG | `[2.6.11]` to EOF is byte-identical to `main` (`4738e634…e085`). The one #84 bullet is under `[Unreleased]` and now names 0028. |
| Commits, links | `check-commits.sh 3f729a3 e602418`: all ok (2 release commits skipped). `check-links`: 219 links, 0 broken. |
| Docs | They say "counted from sign-in", which now holds for every own re-sign path. No doc change in the delta. |
| Security | No High or Medium. See below. |

## L-1 re-check (`dffe9b8`)

`login_session()` keeps `signed_in_at` when the session already holds the same account. Over HTTP (`php -S`, test clock, `session_days` 1, sign-in at T0):
- Sign out everywhere at +6 h, +12 h and +23:59 h, own reset on Users at +18 h, own change on Your account at +20 h and own change in Control Panel → Security at +22 h. The stamp stays T0 throughout, and this device stays signed in (200).
- The second device is signed out. At exactly T0+1 d the session gets 200, and at T0+1 d+1 s it gets 303.
- A real sign-in afterwards gets a new stamp. When an admin resets another account's password, that account is signed out and the admin's stamp is unchanged.
- Every `login_session()` caller (sign-in, register, the button, the three own-password paths, turning sign-in on) was read; none restarts the count for an own session.

## Mutations (SQLite HTTP suite, plus the session and user unit tests)

| ID | Mutation | Result |
|---|---|---|
| K1 | Always `signed_in_at = app_time()` (L-1 reverted) | **Caught** (HTTP ×4) |
| K2 | Keep the stamp even for a different account (uid check removed) | Survives (N-6) |
| M13 | Stale sign-out redirects without `logout()` | **Caught** (HTTP ×2), so N-1 is fixed |
| M12 | Live sign-out without CSRF | **Caught** (HTTP ×4) |
| M01 | Absolute check removed | **Caught** (HTTP ×9) |
| M22 | `login_session()` sets no `signed_in_at` | **Caught** (HTTP ×4) |

## Findings

| ID | Grade | Status |
|---|---|---|
| L-1 | Low | **Fixed** (above) |
| L-2 | Low | **Fixed**: the fixtures are `s3cret-pw`, and the suite passes after the v2.6.10 and v2.6.11 merges |
| N-1 | Nit | **Fixed**: the test checks for a new id and that the old file is deleted (M13 caught) |
| N-4 | Nit | **Fixed** |
| N-2, N-3, N-5 | Nit | Still open, unchanged (downgrade epoch, docs for lowering `session_days`, `$lifeSign` fatal on a failed sign-in) |
| **L-3** | **Low, new** | Introduced by `dffe9b8`. A real sign-in in a browser whose session was dropped (by Sign out everywhere, a password reset or `user signout`) keeps the dropped session's `signed_in_at`, because the file still holds that account's uid. Probe: dropped at +10 h, signed in again at +20 h, then signed out at T0+1 d, 4 h after that sign-in. Effect: sessions end early, never late. Fix: keep the stamp only when the caller asks (e.g. `login_session($uid, keepSignIn: true)` from the button and the own-password paths), or clear the session in `login()`. Add an HTTP check that a re-sign-in gets a new stamp. |
| **N-6** | **Nit, new** | The uid check in the keep logic is untested (K2 survives). The L-3 test would cover it. |

## Security

- Stale sign-out (`da15c83`, test only): the code path is unchanged. The session id must match `^[A-Za-z0-9,-]{22,256}$` and an existing file, and `logout()` only regenerates that id. Cookies with path characters or oversized ids get 303 and delete nothing. A live session without the token still gets 419 and stays signed in.
- L-1 hardening holds: no own action extends the sign-in limit. L-3 only shortens a session.
- No secrets, hosts or tokens in the delta.

**Verdict: PASS** (0H / 0M / 1L / 4N open). L-3 and N-6 should be fixed in this PR or the next session PR.
