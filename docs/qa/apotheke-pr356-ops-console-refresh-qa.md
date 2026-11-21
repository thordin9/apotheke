# QA: apotheke #356, shared tokens, a light header and users in the operator console

- PR: https://github.com/thordin9/apotheke/pull/356 (Front-End), base `main`. The console takes `frontend/src/styles/tokens.css`, gets a paper header in the light theme, and calls `GET /installations/:id/users` (#351) read-only. It also closes the #350 follow-ups.
- Reviewed head: `3af8e651e6b3f88000ed5cdb27495bb280627352`, 0 behind main `268aae4f` (#352), merge state `CLEAN`. Content PASS at `b37ccf09` (against `4637fa18`); catch-up merge `5975657` read merge-only and `3af8e65` (L-2543 fix) read in full. Commits: `3a744e9` (feat), `d3aadef` and `b37ccf0` (docs), merges `1c6e27a` and `5975657`, `3af8e65` (ci).
- Gate: full (console code, a new API call, CSP and fonts). Full diff read, unit, build, bundle and e2e suites locally, 13 mutants.
- CI on `b37ccf09`, all green: CI 44835278293, CodeQL 44835274010, Operator console, PR checks (22/22). On `3af8e651`: CI, CodeQL, Deploy edge and Operator console green; 23 checks pass, 2 deploy jobs skipped.
- Reviewed 2026-11-21 01:50 to 02:10; catch-up and delta read 02:15 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope (`268aae4f...head`): 22 files: the same 21 as at `b37ccf09` with identical per-file hunks (all under `ops/console` plus `docs/operator-console.md` and `CHANGELOG.md`), and `.github/workflows/ops-console.yml`. Nothing under `edge/` or `frontend/`.
- [x] Merge: `1c6e27a` (parents `[d3aadef, 4637fa18]`) differs from `git merge-tree` only in `CHANGELOG.md` (markers dropped, both sides kept), as its body says. `5975657` (parents `[b37ccf0, 268aae4f]`) is the same: only `CHANGELOG.md`, both `[Unreleased]` bullets kept. `VERSION` equals main (3.61.0).
- [x] API contract: the client's users call, `InstallationUserList` and the error copy match `edge/ops/src/api.ts` (403 `primary`, 404, 410 `gone`, 409 `not_startable`/`no_host`, 503 `unavailable`, `display_name` always null, `next_cursor` null). Ids are `encodeURIComponent`ed. No write call or button.
- [x] Rendering: every cell goes through `el()` (`textContent`); there's no `innerHTML` sink under `src`. The audit Detail column shows `before_json`, which `/audit` and the CSV already return.
- [x] CSP: `src/csp.ts` equals the `edge/ops` Worker policy byte for byte, and `scripts/csp.test.ts` reads that file. The preview now sends it.
- [x] Fonts: the 6 woff2 and 5 OFL files equal `frontend/public/fonts` byte for byte, and the built CSS points only at `/fonts/*` that `dist/fonts` contains.
- [x] Tests: unit 24/24, `test:bundle` 2/2, build clean (`tsc` included), e2e 29/29 (axe on each users error, the header colours in both schemes).
- [x] Mutants: 11/13 killed (innerHTML for usernames, the `writes_disabled` and 409 copy, the primary message, "never", the id encoding, the CSP value, no CSP header, the light header, the audit Detail). Survivors are N-2544.
- [x] #350 follow-ups closed: L-2524 (hostile host, contact, audit detail and username render as text), N-2525, N-2526, N-2527 and N-2528.
- [x] Docs: README and `docs/operator-console.md` describe the users screen. The CHANGELOG bullet is under `[Unreleased]` / Added with the full PR URL. Fixtures use `example.test` only; no secrets or real addresses.

## Findings

- **L-2543 (fixed in `3af8e65`):** `ops-console.yml` runs only on `ops/console/**`, but the console now imports `frontend/src/styles/tokens.css`, and its fonts are copies of `frontend/public/fonts`. A token change on main can change the console's colours or contrast without its axe and header tests running. `3af8e65` adds `frontend/src/styles/tokens.css` and `frontend/public/fonts/**` to both the `pull_request` and `push` `paths` lists, and Operator console ran on that head.
- **N-2544:** two branches have no test. The Active column (setting it to always "Yes" survives, although the `pending` and `disabled` fixtures exist) and the empty-list caption (survives). Optional: assert "No" for the pending fixture and the caption for an installation with no accounts.
- **N-2545 (fixed in the PR body):** the PR body still says "#351 is still open" and "built against that response shape". #351 shipped in v3.61.0, and the read matches. The body now says #351 is merged.

0 open High, Medium or Low; L-2543 and N-2545 fixed; N-2544 open, going to the follow-up PR (next free id: 2546).

## Verdict

**PASS** at `3af8e651`, 0 behind main `268aae4f`. The doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #356"), followed by the hash check and green CI on that head. Squash as "feat(ops): shared tokens, light header and users in the console (#356)". As a `feat`, this cuts a minor release.
