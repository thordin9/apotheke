# QA: apotheke #90, CI4 session idle and absolute limits, per-account epoch

- **PR:** [#90](https://github.com/thordin9/apotheke/pull/90), `fix(ci4): session idle and absolute limits, per-account epoch`. Full gate (auth). Ports `main` #84 and #87 to `feature/ci4`.
- **Head:** `1eb0b1f`. **Base:** `feature/ci4` `c3340fe`. 6 commits (`e9fdd21`, `1d942f6`, `a204495`, `54bb9a2`, `7545dc8`, `1eb0b1f`).
- **Verdict: PASS at `ac3865cc8ba8d5b79c8dd8a8979c87a39dd63448`.** 0 High, 0 Medium, 0 Low; 4 Nits.
- **Delta re-check (`ac3865c`):** plain merge of `1eb0b1f` and `feature/ci4` `4fb5639` (#89, QA-passed at `4313b46`). Conflicts only in CHANGELOG (all three Fixed bullets kept under `[Unreleased]`; `[2.` to EOF byte-identical to `4fb5639`) and HANDOFF (both pages combined, neutral wording). `Services.php` and `SettingsService.php` auto-merged with both sides' hunks; `stored()` refuses secret keys and never decrypts. Every other file equals the side that changed it. CI4 suite, PHP 8.4: SQLite 162 OK, PostgreSQL 17 200 OK; session tests 46 OK with a valid and with a malformed `APP_KEY`. Idle, absolute, epoch and `keepSignIn` mutations still caught. CI 22/22 as reported by Coder2 (not verifiable here). The tables below are from `1eb0b1f`.

## Checklist

| Check | Result |
|---|---|
| CI | 21/22 as reported by Coder2; the GitHub API isn't reachable from here. The red Secret scan runs `gitleaks git --log-opts=--all`, so it scans every branch, including #89's. With #90's `.gitleaks.toml`, gitleaks 8.30.1 finds 3 `generic-api-key` hits in #89's `3b203e5` (the two S-L5 test files). It finds none in `c3340fe..1eb0b1f`, and none in #89 with #89's allowlist. #90 doesn't contain #89's commits. The explanation holds. |
| Tests at `1eb0b1f` | PHP 8.4 SQLite: 560 OK (39 skips). PHP 8.4 PostgreSQL 17: 560 OK (6 skips), and the CI4 PostgreSQL proof passes. CI4 module suite: 152 OK. PHP 8.3 SQLite: 560 OK (73 skips); CI4 needs PHP ^8.4, so its module suite skips there, as in CI. |
| Migrations | `migrations/` is byte-identical to `main` (0028 included). A database built by `main` v2.6.14 (SQLite and PostgreSQL 17) gets 0 new steps from the CI4 runner. CI4 reads its stored and fingerprint epochs, so `main` sessions stay valid. Sign out everywhere and a new password then end them. |
| #87 L-3 | Ported. `SessionGate::signIn()` keeps `signed_in_at` only with `keepSignIn` for the same account; a real sign-in starts again. |
| Docs, CHANGELOG | ADR 001, control-panel, AGENTS, PLAN and the new HANDOFF page are updated. Two bullets sit under `[Unreleased]` › Fixed. `[2.6.9]` to EOF is byte-identical to the base. |
| Commits, links, arch | `check-commits.sh` ok (6). `check-links.mjs`: 254 links, 0 broken. deptrac and the DI rule: 0 errors. |

## Over HTTP (CI4 `php -S`, `main`-built SQLite DB)

- **Absolute limit.** A signed-in session last used 30 s ago but signed in 31 days ago is ended and its file is removed. One signed in 30 days minus 1 minute ago is kept. With `session_days` 2, a session signed in 2 days and 5 seconds ago is ended.
- **Idle limit.** At the default 168 h, a session idle 169 h is ended and one idle 167 h is kept. With `session_idle_hours` 1, idle 61 min is ended and idle 58 min is kept.
- **Stamps.** A session without `signed_in_at` gets one and stays. The activity stamp doesn't move within a minute. An anonymous session idle past `session_days` is ended. A cookie containing path characters loads nothing.

## Mutations (CI4 module suite plus the CI4 HTTP tests)

16 of 16 caught:
- the idle check, the absolute check, and the idle `>` changed to `>=`
- the sign-in time moved on every request
- `keepSignIn` ignored, kept across accounts, and always kept (L-3 reverted)
- no id regeneration on sign-in
- stored epoch ignored, epoch renewal not written, and a new password keeping the epoch
- the idle limit wired as `session_days`
- the 8760 h clamp removed
- a left-open session not closed
- `NativeSession::set()` writing
- the activity stamp moved on every request

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-8 | Nit | No CI4 route calls `signIn()`, sign out everywhere or the password change yet (the Auth pages come later). Fixation (a new id, the old file deleted), the epoch and `keepSignIn` were checked against real databases, not over HTTP. Re-check the `keepSignIn` call sites against `main` #87 when the Auth pages land (HANDOFF step 3 records this). |
| N-9 | Nit, pre-existing | A session file that can't be decoded gives HTTP 500 on CI4 (`session_start()` throws through the CI4 error handler). Only the app writes these files. |
| N-10 | Nit, pre-existing | Hardening: route permission checks for the CI4 settings POST routes are due with the PLAN PR 7 Permission filter. CI4 isn't shipped. |
| N-11 | Nit, from #89 | Two #89 `SettingsServiceTest` cases fail when `APP_KEY` is set in the runner's environment (they assume no key). Test isolation only; CI has no key. |

`main`'s N-2, N-3 and N-5 are unchanged. N-5 doesn't apply to CI4.

## Security

- Both limits are enforced on the server on every request with a live cookie. An ended session is emptied and its file is removed.
- The epoch is compared with `hash_equals`. A new epoch is 8 random bytes. Sign out everywhere no longer rehashes the password.
- Sign-in regenerates the id (the old file is deleted) and issues a new CSRF token.
- Worker phase: no new static or global state. The idle limit is read on each request. `$_SESSION` is reset, and a session left open is closed before the next request. `NativeSession` is read-only. Sessions still use PHP's native session functions, as ADR 001 chose; this wasn't run under a worker runtime.
- No secrets, hosts or tokens in the diff.

**Verdict: PASS at `ac3865cc8ba8d5b79c8dd8a8979c87a39dd63448`** (0H / 0M / 0L / 4N).
