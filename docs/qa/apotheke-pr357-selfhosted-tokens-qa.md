# QA: apotheke #357, the self-hosted stylesheet on the shared tokens

- PR: https://github.com/thordin9/apotheke/pull/357 (Front-End), base `main`. `public/assets/app.css` copies `frontend/src/styles/tokens.css`; the hard-coded palette in `Page.php`, `Accent.php`, `Branding.php` and `app.js` follows, and the default accent becomes the brand plum.
- Reviewed head: `0db4474eda5f2214256c478511134a50fd39197f`, 0 behind main `24538dc4` (v3.63.0). First gate FAIL at `6377cc2e` (M-2547); delta `7491cdc` (fix), `15c767b` (test), merge `42c7f77`, `1884bbf` (fix) read in full; `0db4474` (docs) diff read. Commits: `af04137` (feat), `49485ca` (docs), `34abdb4`, `6377cc2` and `15c767b` (test), `7491cdc` and `1884bbf` (fix), merge `42c7f77`, `0db4474` (docs).
- Gate: full (CSS, PHP and JS behaviour). Full diff read, PHP unit suite (648 OK at `6377cc2e`), `SharedTokensTest` and `tests/Modules/Settings` at `1884bbff`, 19 mutants, 2 baselines compared side by side.
- CI on `1884bbff`: 21/21 green; `0db4474e` is CHANGELOG-only, and CI on it must be green before the merge.
- Reviewed 2026-11-21 05:15 to 06:10 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope (`24538dc4...head`): 22 files, as in the PR body. No new cookies, storage, fonts or markup.
- [x] Merge: `42c7f77` (parents `[15c767b, 24538dc4]`) differs from `git merge-tree` only in `CHANGELOG.md` (markers dropped, both sides kept). `VERSION` equals main (3.63.0).
- [x] Tokens: every shared name in `app.css` (light, dark and scheme blocks) equals `tokens.css`.
- [x] Theme-color: light `#fdfcfa` and dark `#07161c` are the light and dark `--color-header`, so the browser bar matches the header; the dark body `#0f252c` is intended. The manifest `theme_color` for the default is `#fdfcfa`.
- [x] Default accent: `ACCENT_DEFAULT` `#5b3b8e` with dark `#b7a0e6` equals the `app.css` `--brand-primary`; "Plum (default)" is the first preset and `.sw-0`; `css(default)` is ''. Indigo is a normal preset with its old dark `#8b93ff`, and a stored `#4f46e5` row stays lowercase and keeps indigo CSS.
- [x] Sync: `Accent::BG_*`, the `app.js` modes, `.pv-light`/`.pv-dark`, `Page.php` and `app.js` `DARK`/`LIGHT` all match, and `SharedTokensTest` now pins them.
- [x] Baselines: the 5 refreshed desktop autofill-settings PNGs differ by palette, accent and `--gutter` spacing only.
- [x] Tests: `SharedTokensTest` 4 tests / 76 assertions, `tests/Modules/Settings` 45 OK (1 skipped), Branding, API and page goldens OK.
- [x] Mutants: first gate 7/9 (the two survivors are covered by CI specs); delta 10/10 killed (`DEFAULT_DARK`, the indigo arm, the `app.js` plum and indigo shades, the stored indigo spelling, the manifest colour, the default label, `.sw-0`, `ACCENT_DEFAULT`, the adjusted flag).
- [x] Docs: the `[Unreleased]` / Changed bullet has the full PR URL and states the plum default and what keeps indigo; the PR body matches. No secrets.

## Findings

- **M-2547 (fixed in `7491cdc`, `15c767b`, `1884bbf`):** the default accent was still indigo in `SettingsSchema`, `Accent` and `app.js` while `app.css` drew plum, so Settings showed "Indigo (default)" and indigo could not be picked. The default is now plum everywhere, and indigo is a preset that a stored row keeps.
- **N-2548 (fixed):** `SharedTokensTest` ties `Accent::BG_*` and the `app.js` modes to the `app.css` paper.
- **N-2549 (fixed):** the manifest `theme_color` for the default is `#fdfcfa`, not `#C8502D`.
- **N-2550 (fixed in the PR body):** the PR body says the `visual*.spec` baselines that aren't run in CI keep the old palette.
- **L-2551 (fixed in `0db4474` and the PR body):** saving or resetting Appearance stores the accent, so those installs keep a `#4f46e5` row and stay indigo; only untouched installs move to plum. The CHANGELOG and the PR body now say so.

0 open findings. Next free id: 2552.

## Verdict

**PASS** at `0db4474e`, 0 behind main `24538dc4`. The doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #357"), followed by the hash check and green CI on that head. Squash as "feat(frontend): align the self-hosted stylesheet with the tokens (#357)". As a `feat`, this cuts a minor release.
