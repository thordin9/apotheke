# QA: apotheke #200, page-view counting with Counterscale after consent (static frontend)

**Gate:** full (touches consent). **Head:** `6f6d90e5a800d8c41f9d61f1cfbb838af3751b4a`. It was reviewed at `abcec74` and `2397c3b`. The delta is `5a116cb` (main merged: #199 and v3.9.0), `69bc92b` (style) and `6f6d90e` (N-1530). It is 0 behind main `3baa3dc`, mergeable and clean, +643/-22 across 20 files. **Reviewer:** Apotheke QA 2.
**Verdict: PASS at `6f6d90e`.** No Highs or Mediums, and no new findings. N-1531 stays open, needs no action and doesn't block.
**Merge condition:** do not merge until the Counterscale Worker is live at the analytics host with Cloudflare Access in front.

## Checklist

- [x] **CI and history.**
  - 23 check runs pass at `6f6d90e`; the deploy jobs are skipped, as usual on a PR.
  - `2397c3b` is an ancestor, so there was no rebase or force-push.
  - `check-commits.sh` accepts `style(frontend)` and `fix(frontend)`, and skips the merge commit.
- [x] **Merge `5a116cb`.** A local 3-way merge from `a26f987` conflicts in the same 3 files. Every other file is byte-identical to the auto-merge.
  - In `CHANGELOG.md`, `check-dist.mjs` and `landing.css`, no added line from either side is missing.
  - `CHANGELOG` has one #200 bullet in [Unreleased] above 3.9.0, with the `([#200](…/pull/200))` link, and `release.php check-links` passes.
  - `check-dist.mjs:201-202` keeps both footer assertions.
  - #199's work survives: the header hover in ink, the 44 px controls, the `role="status"` region and the header and Privacy checks (`check-dist.mjs:215-221`).
- [x] **Local run.** The build passes, as do contrast (90/90) and `check-dist.mjs`. The unit tests pass 13/13 and `deploy-scripts.test.mjs` passes 13/13. The e2e passes 29/29 on chromium, run twice.
- [x] **Cookie settings (`landing.css:149`).**
  - At 360 and 390 px, in light and dark, on `/`, `/privacy/` and `/app/`, it measures at least 121×44 and stays inside the viewport. It matches the footer links (ink, no underline).
  - It is reached with Tab, and `:focus-visible` gives a 2 px solid ring at a 2 px offset. Against the footer background the ring is 7.8:1 in light mode and 7.0:1 in dark.
- [x] **N-1530 fix (`consent.js:78-95`).** Withdrawing clears the CSRF token, disables the fields and the submit button, and resets the note, the error and Home to the state before Accept, which matches `app.js` `renderSignIn`. Even a forced submit sends no request (e2e `consent.spec.ts:456`).
- [x] **Mutations.** 4 of 5 are caught, and the 5th is a guard-only change with nothing to detect (each was reverted, and the tree was checked against the tarball):
  - **`.link-button` min size removed:** caught by e2e `app.spec.ts:221` and `consent.spec.ts:487`.
  - **N-1530 fix reverted:** caught by e2e `:456`.
  - **Cookie settings assertion removed from check-dist:** nothing fails, as expected for a guard alone.
  - **Assertion and the button both removed:** caught by e2e `:411`, `:456` and `:487`.
  - **Per-view re-check removed:** caught by e2e `:388`, `:399` and `:411`.

## Findings

- **M-1511, L-1512 and L-1513, N-1514 and N-1515: fixed at `2397c3b`.** Re-checked after the merge, and all still hold.
- **N-1516: resolved by the merge.** Both conflicts were resolved as recommended, and `.link-button` is restyled to the footer links (`69bc92b`).
- **N-1530: fixed in `6f6d90e`.** Withdrawing on `/app/sign-in` turns sign-in back off, and a new e2e (`consent.spec.ts:456`) covers it.
- **N-1531: open, no action needed.** `stopAnalytics()` is defence in depth. The per-view re-check alone stops counting.
- `docs/analytics.md:77` now uses `<analytics-host>`, so the deploy example uses placeholders only.

## Conditions

Squash-merge with the PR title as the subject, and only once the merge condition above is met. This doc must be the last commit, byte-exact. No secrets, tokens or credentials appear in the diff, the tests or this doc.
