# QA: PR #181, landing page and single Vite build

- **PR / head:** #181 `fe/landing` → `main` (Front-End Specialist), same repo, not draft, mergeable clean. Head `cc74122526212b54854cc402377e0a7dff29c516`, 10 commits since the first review at `db7400708b884e54da577abd16fa33447c1bc0da`: 7 on the branch (including a merge of `main` at `1272ec9`) and the 3 `main` commits it brings in (#174, #175, #179). Base is `main` at `1272ec9`, 0 behind.
- **CI:** 18/18 success on the exact head (CI run 41090177300, Frontend run 41090177485, CodeQL 41090170095), including both required checks `Tests (PHP 8.4, sqlite)` and `Tests (PHP 8.4, pgsql)` and the new `Build` job.
- **Scope:** full gate (CSP and response headers). First review 2026-10-30 at `db74007` (PASS, 0H/0M/1L/5N); this is the delta re-check, 2026-10-30 (EET). Known and accepted, not graded: the `[Operator contact]` placeholder on /privacy/, unminified CSS, the pending domain attachment.
- **Merge precondition from the first review:** met. `main` (with #179) is merged and `/frontend` has its own Dependabot entry and test update.

## Checklist

| Check | Result |
|---|---|
| Merge commit `a927498` | Three-way file comparison against merge base `7202150`, PR side `637da9f` and `main` at `1272ec9`: every file equals the side that changed it, no file changed on both sides, 0 mismatches. No conflict edits |
| Own delta | `frontend.yml` (new), `check-dist.mjs` (new), `_headers`, `404.html`, `index.html`, `dependabot.yml`, `Ci4BranchCiTest.php`. Nothing else outside the merge |
| Build, Node 22.23.4 / npm 10.9.9 | `npm ci && npm run build` clean, 58 dist files; `contrast.mjs` 88/88 pass; `check-dist.mjs` passes; `npm audit` 0 findings |
| `frontend.yml` | Runs on PRs and `main` pushes touching `frontend/**` or itself; `contents: read`; actions pinned by SHA (checkout v4.4.0, setup-node v4.4.0); Node 22; build, contrast and `check-dist` steps; 10 min timeout. Not a required check (path-filtered). actionlint clean; zizmor: 1 low-confidence finding (N-1418) |
| `check-dist.mjs` | Dependency-free; mirrors `_headers` inheritance, comma joining and `!` detach. Asserts the 4 HTML pages and `assets/`, no `<script>` other than JSON-LD on the 3 no-JS pages, the exact landing CSP on 5 landing paths and the exact SPA CSP on `/app/`, the expected Cache-Control on every dist file plus `/`, `/privacy/`, `/app/`, `/404.html`, and that every `href`/`src`/`srcset` in every page is `https:`/`mailto:` or a root-absolute path present in dist |
| Headers (`_headers`, 63 paths evaluated) | Only two changes: the `/404.html` rule removed (N-1412) and HSTS set to exactly `max-age=31536000`, no `includeSubDomains`, no `preload`. Every path gets that one HSTS value plus `nosniff`, `strict-origin-when-cross-origin`, `DENY` and the Permissions-Policy, one CSP and one Cache-Control. `dist/_headers` is byte-identical to `public/_headers`. Same HSTS value as the API (`ApiSecurityPolicy`) |
| Copy | `404.html` has no canonical (built page checked). Photos line reads "Up to 12 photos per item by default (configurable from 1 to 50)", matching the `max_photos` setting (default 12, 1–50) |
| Dependabot | `/frontend` npm entry: weekly, prefix `build` with scope, group `frontend`; YAML parses, 4 entries. Subjects (`build(deps-dev): …`) pass `check-commits.sh`. `Ci4BranchCiTest` pins the whole file and the `package.json` list `['.', 'tests/e2e', 'frontend']`; removing the entry fails the test |
| PHP 8.4.24, touched tests | `Ci4BranchCiTest` 14 tests OK (1 incomplete: no local PostgreSQL), `DockerfileTest` 9 OK |
| Mutations (19 + baseline) | **14 caught:** inline script on `/` (check-dist), module script on /privacy/ (build), duplicate CSP on `/app/` (check-dist), extra CSP rule on /privacy/ (check-dist), `unsafe-inline` landing CSP (check-dist), `unsafe-eval` SPA CSP (check-dist), duplicate Cache-Control on brand assets (check-dist), wrong HTML Cache-Control (check-dist), broken `/privacy/` link (check-dist), missing image (check-dist), page dropped from the build (check-dist), broken Vite input (build), contrast token (contrast), `javascript:` link (check-dist). **5 survived** (N-1416): third-party stylesheet, HSTS with `includeSubDomains; preload`, `nosniff` removed, `X-Frame-Options` removed, inline event handler. Baseline passes |
| Secrets | No credentials or tokens in the delta; CI secret scan green |
| Commits / CHANGELOG / docs | All subjects pass Conventional Commits (`ci`, `fix(frontend)`, `chore`). No CHANGELOG edit, correct per `docs/releasing.md`. `docs/ci.md` and `frontend/README.md` not updated for the new workflow (N-1417) |

## Findings

### H
None.

### M
None.

### L

- **L-1410: no CI coverage for `frontend/`. Fixed.** The new `Build` job runs the build, `contrast.mjs` and `check-dist.mjs`, and 14 of the 19 mutations now fail CI, including 7 of the 8 from the first review (the third-party stylesheet still passes, see N-1416).

### N

- **N-1411: release date in shallow clones.** Handed over to DevOps (deploy job), not open on this PR.
- **N-1412: redundant `/404.html` rule.** Fixed; the comment now says `/*` covers it.
- **N-1413: 404 page canonical.** Fixed.
- **N-1414: host config is a deploy requirement.** Handed over to DevOps (edge config), not open on this PR.
- **N-1415: photo count copy.** Fixed.
- **N-1416: `check-dist` pins CSP and Cache-Control only.** HSTS, `nosniff`, Referrer-Policy, X-Frame-Options and Permissions-Policy are not asserted, so a change to the HSTS value the PM just set, or dropping one of those headers, would pass CI; off-site `https:` subresources are also allowed. All are correct today, and the CSP blocks off-site loads and inline handlers in the browser. Hardening: assert the exact value of each `/*` security header on every path, and allow `https:` only for `href` on `<a>`.
- **N-1417: docs.** `docs/ci.md` still says three workflows and does not describe `frontend.yml`; `frontend/README.md` does not mention `check-dist.mjs` or that CI runs it. Add a short section to each.
- **N-1418: checkout credentials.** `frontend.yml` checks out with the default `persist-credentials`. The job uploads no artefacts and the rest of the repo does the same, so this is hardening only: set `persist-credentials: false`. The setup-node comment could say `v4.4.0`.

## Security

The delta keeps the landing policy on every no-JS path and exactly one SPA policy on `/app/`, now enforced by CI along with Cache-Control, no-script pages and internal links. HSTS is `max-age=31536000` on every path, with no `includeSubDomains` or `preload`, as requested. The other security headers are unchanged but not yet CI-checked (N-1416). No secrets or identifiers were printed or committed during this review.

## Verdict

**PASS** at `cc74122526212b54854cc402377e0a7dff29c516`: 0H/0M/0L open (L-1410 fixed), 3 new Nits (N-1416 to N-1418) that can follow up. N-1412, N-1413 and N-1415 are fixed; N-1411 and N-1414 are with DevOps. The merge precondition is met.
