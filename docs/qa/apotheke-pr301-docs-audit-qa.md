# QA: apotheke #301, correct v3.37.0 storage, backup and sign-in notes

- PR: https://github.com/thordin9/apotheke/pull/301 (QA docs-audit), base `main`. Addresses the 21 stale-doc items from the 2026-11-12 v3.37.0 audit.
- Approved head: `f4510641cfbd3d7a66fea4b70cd320b80107d3df`, a plain `--no-ff` merge of main `3be6990d` (v3.37.1) onto `72b2a3f5`; 0 behind, ahead 4. Content reviewed at `98ca397f` (on main `5e2d3471`); fix commit `72b2a3f5` (docs: correct the executor notes and header-check credit, 56 chars) delta-read. The squash uses the PR title (58).
- Gate: light (docs only: diff read, facts checked against the code, whole-tree docs grep for forbidden claims, CHANGELOG compare, CI, merge-only read). No suites, mutations or Docker.
- Reviewer: Apotheke QA 2
## Checklist

- [x] Scope is 10 files, all docs: `.env.example` (two comment lines), `CHANGELOG.md`, `README.md`, `docs/HANDOFF.md`, `docs/architecture.md`, `docs/deployment-and-operations.md`, `docs/index.md`, `docs/self-host-cloudflare.md`, `docs/user-guide.md`, `frontend/README.md`. No code. Against the review-time base `5e2d3471`, compare is ahead 2, behind 0. Against current main `f3cf7480` it is 1 behind; the overlap with #288 is `CHANGELOG.md` only (`docs/qa/` was not in this PR). The #296 `/app/` router sentence in `frontend/README.md` and the post-flip sign-in check in `docs/deployment-and-operations.md` are unchanged.
- [x] All 21 FINDINGS items are addressed in the diff, and the new wording matches the code at the head. QA's two corrections are present: job rows live in the `jobs` table, which storage mode keeps on D1 (`migrations/0029_jobs.php:7`, `modules/Core/Jobs/JobQueue.php:60`; `docs/self-host-cloudflare.md:187–189`), and `Services::sqlExecutor()` / `Services::unitsOfWork()` are wired (`app/Config/Services.php:294,301–303,310,316` and the repository constructors; `docs/architecture.md:112–114`).
- [x] Forbidden claims, grepped in the head tree's docs (excluding historical `docs/qa/`): no page says FrankenPHP worker mode is on in production (`APOTHEKE_PHP_WORKER` is `"0"` in `edge/wrangler.jsonc:49`; the docs say it ships off). No page says site Decline ends the session (`frontend/README.md` says it does not yet; app-page Decline ending a session is #291 and is scoped to the CI4 pages). No page claims restore already works on d1 (user-facing text says refused / not available yet: `Writes::refuseOnD1(..., 'Restoring a backup')`, `BackupView.php:95–96`).
- [x] CHANGELOG: one `### Changed` bullet under [Unreleased] with the full #301 URL. From `## [3.37.0]` down, 181345 bytes, byte-identical to review-time main `5e2d3471`; the preamble is too. Current main `f3cf7480` adds the #288 [Unreleased] bullet, so the catch-up must keep both bullets. No secrets. Conventional Commits and Secret scan are green on the head. The storage-mode status sentence is byte-identical in `docs/deployment-and-operations.md` (twice) and in `docs/HANDOFF.md` next steps. Live `GET /healthz` is 200, body `ok`, `x-apotheke-version: 3.37.0`.
- [x] Fix commit `72b2a3f5` (4 files, +10/-7, docs only) closes L-2031 and N-2032: `docs/database.md` now says call sites use `sqlExecutor()` and `unitsOfWork()`, with `Dialect::inList()` used in `ItemList.php:53,64`, `SearchIndex.php:323` and `Stocktakes.php:97`; the ADR gets a dated Status note and its decision text is unchanged; `frontend/README.md:86` credits `check-dist.mjs` for `dist/_headers` and `scripts/app-worker-check.mjs` for the Worker. The CHANGELOG bullet adds "and the SQL executor".
- [x] Merge-only read at `f4510641`: parents `72b2a3f5` and main `3be6990d`; behind 0. The PR's own diff outside `CHANGELOG.md` against main is line-identical to its diff at `72b2a3f5` against `5e2d3471` (156 lines, cmp equal). `CHANGELOG.md` against main adds only the #301 `### Changed` bullet under an otherwise empty [Unreleased]; #288's note sits in main's `[3.37.1]`, untouched.
- [x] CI on `98ca397f`: CI 43274235937, Frontend 43274236061, Deploy frontend 43274235876 and PR #301 43274219923. Frontend, Deploy frontend and PR #301 succeeded (Deploy getapotheke-app skipped, as on PRs). CI was still in progress at review: 21 check runs succeeded, 2 in progress (Tests PHP 8.4 sqlite and pgsql). CI on `f4510641` had just started at the merge-only read; it must be green on that head (and on the docs(qa) commit) before merge.

## Status of the 21 items

All 21 are addressed. README, index (2), self-host-cloudflare (6, including the jobs-on-D1 correction and a Storage section that still marks bind-and-create steps unwritten), HANDOFF (5; next finding ids removed, not invented; the `EDGE_STORAGE_READY` flip marked done), deployment-and-operations (2), user-guide, frontend README (2), architecture (including the wired-executor correction), .env.example.

## Findings

- **L-2031 (closed at `72b2a3f5`):** `docs/database.md` and the ADR-002 PR-split plan said call sites would move onto the executor later and only the list binding was in use.
- **N-2032 (closed at `72b2a3f5`):** `frontend/README.md:86` credited `check-dist` with the Worker comparison.
- **N-2033:** with v3.37.1 released, `docs/HANDOFF.md:10, 46, 59, 69` still name v3.37.0 as the latest/current release. The statements stay true of v3.37.1 (storage mode, worker mode off); follow-up.

0 High, 0 Medium, 0 open Low, 1 open Nit.

## Verdict

**PASS** at `f4510641`. Clear to squash-merge once CI is green and this doc is committed unchanged as the last `docs(qa)` commit and hash-checked, in the PM's merge order. If main moves first, a plain catch-up, a merge-only read and a reissued doc are required. The squash subject is the PR title.
