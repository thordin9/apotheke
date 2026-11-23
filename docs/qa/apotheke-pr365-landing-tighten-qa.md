# QA: apotheke #365, tighter landing, light shots, consent fit

- PR: https://github.com/thordin9/apotheke/pull/365 (Front-End), base `main`. Round 2 PR B (`/workspace/design-audit/round2.md` §5): sticky consent (N-2540), light and dark product shots via `<picture>`, capped hero with the phone shot at ≤40rem, one `.btn-primary` recipe plus `.btn-s`, ruled features list, `components.css` deleted, N-2539 and N-2541, plus the 320 header fit and the follow-up that keeps the footer and app card in view. No `edge/`, PHP, consent cookie or analytics change. `consent.js` is untouched.
- Reviewed head: `f548e2655a6685a59a9ba0889679d16d4b823afa`, 0 behind main `835c836a` (v3.70.0), merge state clean. CI on `f548e265`: 24 success (includes the ops-console "Build, unit and end-to-end" job from #364), 1 skipped (Deploy getapotheke-app), 0 failed. Content FAIL at `a3fd4cd0` (M-2580, M-2581). Fixes reviewed at `8928b0df` (then 1 behind after #364). Catch-up merges `4fe15ebf` and `f548e265` read merge-only.
- Gate: full at `a3fd4cd0` and `8928b0df`; merge-only plus a local frontend build, contrast and e2e at `f548e265`.
- Reviewed 2026-11-21 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] History: **FAIL** at `a3fd4cd0` — M-2580 (`.scroller` as a flex column plus `.doc { min-height: 50svh }` parked the footer mid-article on `/privacy/`) and M-2581 (the app heading sat above the scroller at 320 and 375 with the notice up). L-2582 the phone-list figcaption still said "The list, dark."; N-2583 no test for the ≤40rem phone hero source; N-2584 dropping the focus pair left contrast green.
- [x] Fixes at `8928b0df` (`fix(frontend): keep the footer and app card in view`, 51 characters): marketing `.scroller` is block layout; the app scroller stays flex with `#main { flex: 1 0 auto; align-items: safe center; min-height: auto }` and `flex-shrink: 0` on the footer; caption "The list."; contrast asserts the header-focus pair; new e2e covers privacy/404 footer clearance, the app card at 320×640 and 375×667, the phone source, the caption and consent `position: sticky`. Re-measured M-2580/M-2581 at that head: `main.offsetHeight === scrollHeight` on `/privacy/`, footer follows the article and clears the bar; app h1 top is 81px and visible. Build clean; contrast 92/92 (before #364); unit 41/41; e2e 96/96. Mutants 5/5 killed, including the three that had survived at `a3fd4cd0`.
- [x] `4fe15ebf` is a plain merge of #364 (`2e65eb0d`) into `8928b0df`, not extra unique work. The 21-file delta vs `8928b0df` is #364 plus the resolution: every file except `CHANGELOG.md` and `contrast.mjs` is byte-identical to `2e65eb0d`. `contrast.mjs` auto-merged (#364's six console pairs and #365's header-focus pair plus the presence check). `git merge-tree` of the parents conflicts only in `CHANGELOG.md`; the merge commit differs from that tree only by dropping the markers and keeping both sides' `[Unreleased]` bullets. Released sections from `## [3.69.0]` were byte-identical to main at that merge.
- [x] `f548e265` (parents `4fe15ebf` and `835c836a`): `git merge-tree --write-tree` is a clean auto-merge, but it placed the #365 bullets inside `## [3.70.0]`. The merge commit differs from that tree only in `CHANGELOG.md`, moving those bullets back under `[Unreleased]`. `CHANGELOG.md` vs main is only those three #365 `[Unreleased]` lines (one Changed, two Fixed), all linking the full PR URL. Released sections from `## [3.70.0]` are byte-identical to main. `VERSION` equals main (3.70.0). The only other files in `4fe15ebf..f548e265` are the release `README.md` and `VERSION`.
- [x] At `f548e265`: frontend build clean; contrast 104/104 (#364's pairs plus the header-focus pair); e2e 96/96. All 5 non-merge subjects pass `check-commits.sh`. Squash subject `feat(frontend): tighter landing, light shots, consent fit (#365)` is 64 characters.

## Findings

No new findings. M-2580, M-2581, L-2582, N-2583 and N-2584 were fixed at `8928b0df` and still hold after the catch-up (next free id: 2585).

## Verdict

**PASS** at `f548e265`, 0 behind main `835c836a`. This doc lands byte-exact as the last commit (`docs(qa): add QA 2 review for #365`), followed by the hash check and green CI on that head. Squash as `feat(frontend): tighter landing, light shots, consent fit (#365)`. As a `feat`, this cuts a minor release.
