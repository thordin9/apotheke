# QA: apotheke #199, a polished, modern look across the site and the app (static frontend)

**Gate:** full (UI restyle across the shared chrome). **Head:** `7428f650aea54907eaac8871d1b2fc9a6b67d726`, first reviewed at `dd863da`. The delta is one fix commit, `7428f65`, on `dd863da`. It is 0 behind main (`a26f987`, v3.8.0), mergeable and clean, +195/-79 across 10 files. **Reviewer:** Apotheke QA 2.
**Verdict: PASS at `7428f65`.** No Highs or Mediums. All three Lows and N-1521 to N-1524 are fixed. N-1532 is new and optional; it doesn't block.

## Checklist

- [x] **CI.** 22 check runs are green at `7428f65`, CodeQL included; Deploy getapotheke-app is skipped, as usual on a PR.
- [x] **Local run.** `npm ci --ignore-scripts` and `npm run build` succeed. `contrast.mjs` 90/90 and `check-dist.mjs` pass. `node --test` 12/12. The frontend e2e passes 21/21 on chromium.
- [x] **Scope (first pass, still holds).** Privacy is in the shared footer only. check-dist enforces identical footers and header chrome. The tokens `--color-header`, `--color-header-ink` and `--shadow-rest` are set in light and dark. The SVG icons are `aria-hidden`.
- [x] **Constraints (first pass, still holds).** The CSP, `_headers` and `edge/` are unchanged. There are no new hosts, fonts or inline scripts, and no storage before consent. `consent.js` and the banner are untouched. Dark mode comes from `prefers-color-scheme`. There is no `/cache` string in `src/` or `dist/`.
- [x] **Measured at `7428f65`.** On all 5 pages at 360, 390 and 1280 px, every header and footer control is at least 44x44; the smallest is footer "Docs" at 44.0 px wide. There is no horizontal overflow, and the header is one row. On header hover, the wordmark and quiet links stay header-ink (13.70 in light, 16.44 in dark), and the links are underlined. The focus outlines are unchanged from the first pass.
- [x] **Mutations.** 4 of 6 delta mutations are caught:
  - Footer link at 32 px: caught by the new 44 px e2e. It survived at `dd863da`.
  - Site-wide `:focus-visible` outline removed: caught by the new focus e2e. It survived at `dd863da`.
  - Privacy in the header outside the nav: caught by check-dist ("a header still links to Privacy") and 3 e2e tests.
  - `#sign-in-error:empty` back to `display: none`: caught by 4 e2e tests.
  - Survived: header hover back to accent (N-1532), and the card hover transform re-added. The transform is design-only, and `base.css:25` still stops motion under reduced motion.

## Findings

- **L-1518: fixed.** `landing.css:25-26,30`: the wordmark and header links keep `--color-header-ink` on hover, and the links gain an underline in the same colour.
- **L-1519: fixed.** `landing.css:145`: footer links have `min-width: 44px` and `min-height: 44px`. A new e2e test, "header and footer controls are at least 44px", runs at 390 and 1280 px.
- **L-1520: fixed.** `landing.css:195`: an empty `#sign-in-error` now collapses with `height: 0` and `overflow: hidden` instead of `display: none`. In Chromium's accessibility tree it stays an unignored `status` node with live set to polite, both when empty and after an error. It has no `aria-hidden` and no hidden ancestor. The e2e asserts that it is present, empty and not `display: none`.
- **N-1521: fixed.** `check-dist.mjs:215-217` refuses `href="/privacy/"` anywhere in each `<header>`.
- **N-1522: fixed.** A new e2e checks the `:focus-visible` outline on the hero CTA and the footer Docs link.
- **N-1523: fixed.** Both stale P7 comments are gone from `index.html`.
- **N-1524: fixed.** The cards have no hover transform or transition.
- **N-1532: new, optional.** No test covers the header hover colour, so reverting L-1518 passes CI. The `contrast.mjs` pair checks the tokens, not the `:hover` rules. Fix: an e2e step that hovers "Sign in" and expects its colour to equal `--color-header-ink`.

## Merge order with #200

Merge #199 first; #200 then merges main, with no rebase or force-push. At #200's head `2397c3b`, `check-dist.mjs` conflicts because both PRs add a line after the operator-line check; keep both. `landing.css` also conflicts: move `.link-button` after #199's `.mast` block. `footer.html` and `app.js` merge cleanly. Restyle `.link-button` to match the footer links (ink, no underline), and keep it at least 44x44, which the new e2e will now enforce.

## Conditions

Squash-merge with the PR title as the subject. This doc must be the last commit, byte-exact. No secrets, tokens or credentials appear in the diff, the tests or this doc.
