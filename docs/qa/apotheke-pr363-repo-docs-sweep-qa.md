# QA: apotheke #363, repo docs sweep for v3.68.0

- PR: https://github.com/thordin9/apotheke/pull/363 (Coder2), branch `docs/repo-sweep`, base `main`. Docs only: 11 Markdown files, +51/−137, no code, workflow, config or `VERSION` change.
- Reviewed head: `2a6d99315f9e4540d8fce78438403dc9c03c115d`, 0 behind main `4ac97ed4` (v3.68.0, after #359 `5445948a`).
- Gate: light (docs only, minor-change rule). Diff read in full, every claim checked against main at v3.68.0; `scripts/check-links.mjs` and `scripts/check-commits.sh` run. No suites, mutants or Docker.
- Reviewed 2026-11-21 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope: `CHANGELOG.md`, `README.md`, `docs/HANDOFF.md`, `docs/ci.md`, `docs/deployment-and-operations.md`, `docs/future/workers-migration-plan.md`, `docs/index.md`, `docs/operator-console.md`, `docs/plans/feature12-ci4/PLAN.md`, `docs/self-host-cloudflare.md`, `ops/console/README.md`.
- [x] Console writes: no changed line says writes are on. `operator-console.md` still says the console ships read-only, every write is 403 `writes_disabled` while `OPS_WRITES_ENABLED` is not `"1"`, and writes, provisioning and limits stay off (lines 451–452, 681). Both Workers ship `OPS_WRITES_ENABLED: "0"` on main. One HANDOFF line is unqualified (L-2561).
- [x] Console live: `OPS_DEPLOY_ENABLED` is `1`, so "live at `console.getapotheke.com`, behind Access" matches the repo state. `deploy-ops` is named *Deploy apotheke-ops*, needs `check` and `deploy`, uses the `deploy-edge` environment, as `ci.md` says.
- [x] Three Workers: the guard's allowlist on main is `getapotheke-app`, `apotheke-api`, `apotheke-ops`, as `deployment-and-operations.md` and `self-host-cloudflare.md` now say.
- [x] Lifecycle Workflow on `apotheke-api` (#359): matches `edge/ops/wrangler.jsonc` (`script_name: apotheke-api`) in HANDOFF, the Workers table and the migration plan.
- [x] d1 restore: README and HANDOFF match #305/#308 and the existing text in `deployment-and-operations.md`.
- [x] wrangler 4.164.0 in `self-host-cloudflare.md` matches `edge/package.json` (#343).
- [x] CHANGELOG: drops the doubled #342 bullet in 3.65.1; no other release section touched.
- [x] Public wording: 0 account, D1 or zone ids, 0 UUIDs or 32-hex ids, 0 emails, 0 tokens or keys in added lines. The operator email stays `<operator email>`.
- [x] Links: 466 relative links in 346 files, 0 broken.
- [x] Commits: `433eb5be` and `2a6d9931` pass `check-commits.sh`. Squash subject `docs: refresh repo docs for v3.68.0 and the live console (#363)` is 63 characters.

## Findings

- **L-2561:** `docs/HANDOFF.md` says the console Worker "binds it and starts a confirmed job". Job create and confirm are writes, which are 403 `writes_disabled` today, and the Workflow also refuses while apotheke-api's flag is `"0"`. Suggest: "binds it; it can start a confirmed job only once both Workers' `OPS_WRITES_ENABLED` is `"1"` (off until writes are switched on)". Fix in this PR or the next docs PR.
- **N-2562:** `docs/HANDOFF.md` says the docs sweep has "No pull request yet"; it is #363. Drop the line or link the PR.
- **N-2563 (pre-existing, not in this diff):** the 3.68.0 section of `CHANGELOG.md` lists #358 twice under *Maintenance* with different wording; worth dropping one the same way as #342.
- **N-2564 (pre-existing, not in this diff):** `docs/deployment-and-operations.md` repeats the "The hosted API is live in storage mode …" paragraph (lines 632 and 659).

## Verdict

**PASS** at `2a6d9931`. No High or Medium. L-2561 and the nits can ride with this PR or a later docs PR; a fix commit gets a delta read and a reissued doc.
