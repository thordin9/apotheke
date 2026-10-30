# QA: PR #167, security policy and brand NOTICE

- **Head:** `cf0959e6d272ae3773e1df6c8e11cd3395a99a5d`. 6 commits, base main `b96f934`, 0 behind, mergeable, same repo.
- **Diff:** 5 files, +67/−0 (SECURITY.md, README link, `.dockerignore`, `frontend/brand/NOTICE`, `frontend/brand/og-1200x630.png`).
- **CI:** run 41044851027 14/14 and CodeQL run 41044846485 on this exact head; all 17 PR checks green, including Conventional Commits.
- **Verdict: PASS.** No H/M. N items below.

## Checklist

- [x] **Scope.** Only the five files above. Nothing under app/, modules/, public/, Docker/compose/entrypoint, composer, or workflows.
- [x] **`.dockerignore`.** Adds `frontend/`. The Dockerfile never COPYs `frontend/` (only app, modules, public, migrations, bin, spark, docs/api/openapi.yaml, vendor). The new line is exact; it does not broaden other patterns. Docker build + smoke already passed in CI.
- [x] **SECURITY.md.**
  - Supported versions match the #162 docs: 3.x yes; 2.6.x security fixes for 90 days after 3.0.0 from branch `old` (README, CONTRIBUTING, AGENTS).
  - Private vulnerability reporting is enabled on the repo (`GET …/private-vulnerability-reporting` → `enabled: true`). The report URL is the GitHub advisories form.
  - No personal emails, phone numbers or private hostnames (only `github.com/thordin9/apotheke`).
  - Targets: acknowledge in 3 working days, plan in 14, fix in 90 (N-1160).
- [x] **NOTICE (L-1081).**
  - Covers `frontend/brand/`, `frontend/public/assets/brand/` and `frontend/public/assets/mascot/`; those paths exist (brand marks and 19 poses).
  - Explicitly excludes the icon sprite and the OFL fonts (fonts keep their own texts).
  - Says the artwork is not under MIT and may be used only to refer to Apotheke; no trademark-registration claim.
  - Compatible with the repo MIT licence for code: artwork carved out, not a conflict with MIT for the application.
  - README Licence section links to `frontend/brand/NOTICE` and the path resolves.
- [x] **PNG.** Valid 1200×630, 73 431 B. Chunks are only IHDR/IDAT/IEND; no tEXt/iTXt/zTXt/eXIf/tIME. Not referenced elsewhere yet (as claimed).
- [x] **Commits.** All six subjects pass `check-commits.sh` (docs / build / chore / docs(frontend)).
- [x] **Secrets / CHANGELOG.** gitleaks 8.30.1 clean on the range; `.gitleaks.toml` unchanged. Released CHANGELOG sections byte-identical to main (no CHANGELOG edit in the PR).

## Findings

**N-1160: public response-time targets.** SECURITY.md commits to acknowledge within 3 working days, confirm and plan within 14, and release a fix within 90 days, with a one-maintainer caveat. That is a public commitment Stathis should be comfortable owning. No wording change required for merge if he accepts it.

**N-1161:** the OG image is not wired into any HTML or meta tags yet. Fine for this PR; a later PR should reference it when social previews are added.

**N-1162:** NOTICE names the owner as “thordin9” (GitHub login). LICENSE uses “Stathis Papandreou”. Same person; optional to align the notice with the legal name later.

## Notes

Closes the #163 follow-ups L-1081 (provenance NOTICE) and N-1082 (`frontend/` in `.dockerignore`) for the Front-End Specialist's next PR.
