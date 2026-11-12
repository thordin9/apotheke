# QA: apotheke #286, sign in against the configured API

- PR: https://github.com/thordin9/apotheke/pull/286 (Front-End), base `main`. Fixes the live-audit findings H-1920 and L-1925.
- **Approved head: `6a8b330884e3a51295f8968149e6d129a7e6717b`**, 0 behind main `644a5d1d` (v3.36.7, includes #287 `00afbb55`), mergeable state clean. This doc replaces the version with sha256 `38275e9a…` (head `1ea3ee7b`), which is void.
- Earlier gates: full gate PASS at `e1c7f9b8` (26 unit, 34 e2e, 21 mutants), merge-only read at `1ea3ee7b`. This is a delta gate from `1ea3ee7b`.
- Delta commits: `0fd776db` fix(frontend): point the post-flip check at site sign-in (56 chars), `90ca4d45` fix(frontend): keep the API host out of the landing CSP (55), `739d7a74` fix(frontend): tighten sign-in errors and the session (53), `0b186610` plain merge of main (parents `739d7a74`, `644a5d1d`; check-commits skips merge subjects), `6a8b3308` fix(frontend): match the API host as a CSP source (49).
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only part: `0b186610` against its first parent changes exactly the files main changed from `48d2fbc1` to `644a5d1d` (README, VERSION, the #287 QA doc, `modules/Backup/*`, `D1BackupCreateTest.php`, CHANGELOG). Every file outside this PR's own 12 is byte-identical to main `644a5d1d` at the head. `6a8b3308` after the merge touches only `check-dist.mjs` and its test.
- [x] CHANGELOG: [Unreleased] holds only the #286 Fixed bullet with the full PR URL; [3.36.7] and everything below are byte-identical to main. Subjects are Conventional Commits, all at most 72 chars.
- [x] Own delta (10 files in `0fd776db`, `90ca4d45`, `739d7a74` and `6a8b3308`) read in full. No secrets, no new dependencies, `/app/*` CSP in `_headers` unchanged, landing `_headers` now byte-identical to main.
- [x] Consent: `/app/` calls `GET /session` only when the consent cookie is set or after Accept; with no consent it makes no API call (pinned: `gets` is 0 before Accept). The username is set with `textContent`.
- [x] CSRF: the token stays in module memory only (no storage, URL or log); the 200 `csrf_token` from POST is kept. The retry is still once, and only for 403 `csrf.invalid`.
- [x] Busy state: the submit is `disabled` with `aria-busy="true"` from the POST until the answer (cleared in `finally`); a second submit while busy returns early. It renders greyed at both sizes.
- [x] Contract: mocks now use `ApiResponse::problem`'s shape (`type`, `title`, `status`, `detail`, `code`), `ApiCsrf::DETAIL`, and `RateLimiter`'s 429 text; the `/session` mock matches `SessionController::state()` (`signed_in`, `user.username`, `csrf_token`).
- [x] Tests on Node 24 from the head tarball, as in `frontend.yml`: build, contrast, unit 28/28, check-dist passes, Playwright 40/40 (34 before, plus 6 new).
- [x] Browser at 390x844 and 1280x800, API mocked: signed-in line and busy button shown, no horizontal scroll, console shows only the known `bluetooth` Permissions-Policy line (L-1923, fixed by #285). Screenshots in `pr286/`.
- [x] Mutations: 9 of 13 killed. Killed: no busy state at all, a failed config read cached, the API origin back in the landing CSP (unit and check-dist), Retry-After ahead of the API detail, a second retry, a retry on any 403, `innerHTML` for the detail, `GET /session` without consent, a substring CSP match. Survived: removing only `disabled` or only the busy guard (each covers the other), the POST token not kept, `innerHTML` for the username.
- [x] CI on `6a8b3308` is green: 24 check runs, 23 success and 1 skipped (Deploy getapotheke-app, as on PRs). Runs: CI 43224852050, Frontend 43224852130, Deploy frontend 43224851929, CodeQL 43224843529.

## Status of earlier findings

- **L-1945** fixed: the post-flip check now signs in at the site's `/app/sign-in` with no "being fixed" note, and `frontend/README.md` says sign-in reads `apiBase` from `/config.json` and calls the API origin.
- **L-1946** fixed: the landing CSP is back to main's; the API origin is in the `/app/*` policy only. check-dist and its test assert both sides by whole source token, and the self-host table says `/app/*` only.
- **L-1947** fixed: e2e tests for a second `csrf.invalid` (2 POSTs, detail shown) and a different 403 code (1 POST).
- **L-1948** fixed: a markup-bearing `detail` renders as literal text with no element created.
- **L-1949** fixed: busy submit, and a double-click test expects 1 POST.
- **L-1950** fixed: `/app/` shows "Signed in as …" after a consented `GET /session`; the POST token is kept.
- **N-1951** fixed: only a successful config read is cached; a unit test pins the read count.
- **N-1952** fixed: the API's 429 detail comes first; without one, `Retry-After` is shown in minutes.
- **N-1953** fixed: mocks mirror the API (the `consent_required` mock detail is still the test's own, with no effect: the site shows its own wording for that code).

## New findings

- **L-1966:** the post-flip check now says to sign in on the site and then "save an item with a photo", but the site's `/app/` has no item screens until S1. Say where to save it (for example the API host's own pages, which use the same session).
- **N-1967:** the `frontend/README.md` router line still describes `/app/` as "what Apotheke is, and a link to sign in", and the CHANGELOG bullet leaves out the signed-in line and the busy submit.
- **N-1968:** three mutants survive. Hardening: assert `toBeDisabled()` in the double-click test, use a markup-bearing username in the signed-in test, and either pin or drop keeping the POST's token (the `/app/` view reads `GET /session` again right after).

0 High, 0 Medium, 1 Low, 2 Nits open.

## Verdict

**PASS** at `6a8b3308` (CI 43224852050, Frontend 43224852130, Deploy frontend 43224851929). All of L-1945 to N-1953 are fixed. Squash-merge with the PR title as the subject. L-1966 is a one-line docs fix and is best made before merge, because the site deploys on merge; it does not block. N-1967 and N-1968 can go in a follow-up. If main moves before merge, a merge-only read and a reissued doc are needed.
