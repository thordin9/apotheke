# QA: apotheke #364, clearer console layout and read-only writes

- PR: https://github.com/thordin9/apotheke/pull/364 (Front-End), base `main`. 6 commits, 20 files, +860/−264: round 2 PR A (`/workspace/design-audit/round2.md` §5). `ops/console` UI (tiles, facts lists, scroll regions, `<time>`, states, header, breadcrumb, disabled write controls), `writes_enabled` on `OpsConfig` with `writesAreEnabled()`, console pairs in `frontend/scripts/contrast.mjs`, the ops-console workflow path filter, the users paragraph in `docs/operator-console.md`. No `edge/`, PHP, migration, `VERSION`, `client.ts` request logic or `csrfHeaders()` change.
- Reviewed head: `315b6bdfa96c4c70ec0b80c4aa6aece80186ff13`, 0 behind main `f47139cb` (v3.69.0, after #362 `59740b29`), merge state clean. Full review at `7f2b86bc`; delta `3946850e` (fixes) read in full; `315b6bdf` is a merge of `f47139cb`, read merge-only. CI on `315b6bdf`: 25 success, 3 skipped (the three deploy jobs, as on every PR), 0 failed.
- Gate: full (UI on the console whose backend now has writes on). Build, unit, bundle and Playwright e2e, contrast, links, commit subjects and mutants run locally.
- Reviewed 2026-11-21 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Read-only: `writesAreEnabled()` returns `writes_enabled === true`, so missing or non-true is false. Every write control is `<button type="button" aria-disabled="true" aria-describedby="writes-off">` from `writeControl()`, with no handler and no form. The flag only toggles the Read-only pill, the note and `body[data-writes-enabled]`. Production `/config` now reports `writes_enabled: true` (#362), so the live console hides Read-only and reads "Changes come in a later release.", which is accurate: it still sends no write (spec D-1, the PM's call).
- [x] Preview cookie: `ops_preview_writes` is read only by the dev/preview middleware and the mock; `dist/` has 0 files containing `ops_preview`, `previewWrites`, `handleMock` or `example.test`; `test:bundle` passes.
- [x] Tables, dates, states, N-2544, N-2546, contrast and CSP as at `7f2b86bc`: focusable labelled scroll regions with no clipping at 320/768/1440, `<time datetime>` on every timestamp, loading/401 Reload/503 Try again/empty states asserted, overrides gone, `scripts/csp.test.ts` and `edge/` untouched; `contrast.mjs` 102/102.
- [x] Tests at `315b6bdf`: build (`tsc` + vite) clean, unit 29/29 (new `writes.test.ts`), bundle 2/2, e2e 41/41.
- [x] Mutants at `7f2b86bc`: 6/8 killed; the 2 survivors became L-2567 and L-2568. Mutants on `3946850e`: 8/8 killed. These were: `writesAreEnabled` as `!== false` (unit + e2e), `render.ts` treating a missing flag as on (e2e). With the flag on: a click POST, an Enter POST, a click DOM change, a click `pushState`, a POST from Confirm only. Also the breadcrumb separator without `aria-hidden`.
- [x] Breadcrumb: `nav[aria-label=Breadcrumb] > ol`, a CSS-coloured `/` separator with `aria-hidden`, `aria-current="page"` on the current item; users screens now read "Installations / Users". axe clean.
- [x] CHANGELOG `[Unreleased]` *Changed* bullet links the full PR URL. All 6 subjects pass `check-commits.sh`; squash subject `feat(ops): clearer console layout and read-only writes (#364)` is 61 characters. Links: 468 in 348 files, 0 broken.
- [x] Merge-only read (`git merge-tree` of `3946850e` and `f47139cb` against `315b6bdf`): only `CHANGELOG.md` conflicted; the resolution keeps #364's bullet under `[Unreleased]` and drops the markers. From `## [3.69.0]` down it is byte-identical to main, and the whole file differs from main only by the 4-line #364 block. `docs/operator-console.md` auto-merged; `VERSION` 3.69.0.
- [x] Public wording: secret scan of the 87 lines added in `3946850e`: 0 UUIDs, 0 32-hex ids, 0 tokens or keys, 0 emails.
- [x] Overlap with #365 (Front-End, `a3fd4cd0`): `CHANGELOG.md` conflicts in `[Unreleased]`, and `frontend/scripts/contrast.mjs` auto-merges. Whichever merges second needs a catch-up, a reissued doc and a `contrast.mjs` rerun.

## Findings

- **L-2567 closed** (`3946850e`): the writes-on e2e test clicks and presses Enter and Space on every write control on four screens, and asserts no non-GET request, no URL change and no `#main` change.
- **L-2568 closed** (`3946850e`): `writesAreEnabled()` plus a unit test, and an e2e test that serves `/config` without the field.
- **N-2569 closed** (`3946850e`): the users paragraph now describes the status pill, the Active column and the disabled Disable/Re-enable.
- **N-2570 closed** (`3946850e`): the breadcrumb has a hidden separator and `aria-current`.
- **N-2571:** `docs/operator-console.md` says "the header shows Read-only while writes are off" and that writes are enabled by the approved deploy. It doesn't say that in production (writes `"1"`) the console hides Read-only, shows "Changes come in a later release." and sends no write, so writes are API-only for now. Suggest one sentence next to the users paragraph or under *Deploying the console*.

## Verdict

**PASS** at `315b6bdf`. No High or Medium; L-2567, L-2568, N-2569 and N-2570 are closed. N-2571 can ride with a later docs PR. A fix commit or main move gets a delta read and a reissued doc.
