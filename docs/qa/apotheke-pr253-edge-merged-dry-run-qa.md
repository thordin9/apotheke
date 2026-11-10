# QA: apotheke #253, dry-run the merged storage config and ready the flip

- PR: https://github.com/thordin9/apotheke/pull/253 (DevOps)
- Reviewed head: `dcecdde7a49bbcd4bc319a5fba24117444fc0a4c` (docs-only `dcecdde` on top of `111c1f9`; `111c1f9` merges `origin/main` at `cddcd8f` = #252; 1 behind current main `caaf2f0` = v3.29.0)
- Gate: full (diff read, CI, local merge/guard/dry-run, mutations; docs delta after `111c1f9` also read)
- Merge-only read: `0d2f99d7f6bcf532d21e0fdcd6b7834c82f38081`. `509c308` plain-merges main `caaf2f0` (v3.29.0; `CHANGELOG.md`/`VERSION` only, N-1559 fixed). `0d2f99d` plain-merges main `31d66f9` (#255; brings in only `ci.yml` and the #255 doc). The PR's own files are unchanged from the PASS head. 0 behind, clean, 23 green, 2 deploys skipped.
- Reviewer: Apotheke QA 2

## Checklist

- [x] First reviewed at `111c1f9`. The merge commit is plain: second parent `cddcd8f` was main (#252). The delta vs that base is only the five PR files. No config changes.
- [x] Docs delta `111c1f9...dcecdde` is one commit, `docs(edge): mark the EU bucket and lifecycle rule done` (54 characters), +5/-5 in `docs/deployment-and-operations.md` only. Checklist 1.3 is marked done (bucket exists in `eu`, EEUR). 1.9 is marked done: rule `apotheke-primary-abort-incomplete-1d`, applied 2026-11-10 11:13 EET, default whole-bucket 7-day abort kept; owner is no longer "pending approval"; the recreate command uses that rule name. No ids, tokens or internal hosts. Reviewed.
- [x] CI at `111c1f9`: 23 checks green, including Check and dry run (apotheke-api), D1 (local workerd), Secret scan, Conventional Commits and CodeQL. Both deploy jobs skipped. At `dcecdde` the docs path did not retrigger that suite; CodeQL and both Analyze jobs are green (3 success). mergeable_state is still dirty because main moved on by `chore(release): v3.29.0`.
- [x] Check job: `permissions: contents: read`, `pull_request` (not `pull_request_target`), checkout / setup-node / setup-php pinned by SHA. After the default dry run it merges with dummy D1 id `00000000-0000-4000-8000-000000000000`, runs the Worker guard, then `wrangler deploy --dry-run`. No secrets or `vars.` in the check job.
- [x] Local run at `111c1f9` (PHP 8.4.24, Node 24 / wrangler 4.157.0). `DeployWorkflowsTest` 27/27 (383 assertions). `deploy-scripts.test.mjs` 33/33. Merge + guard succeed. No Docker here, so the image-build dry-run was not repeated; `wrangler deploy --dry-run --containers-rollout none` on the merged file prints exactly the binding lines the workflow greps, and no `COOKIE_DOMAIN`. CI already ran the full image-building dry run.
- [x] Docs: prerequisites 1.1–1.11, token scope, merged dry run, preview plan, flip, post-flip and rollback match the workflow and config. Wrangler syntax checks out (`preview` / `--ignore-base-config` / `preview secret put` / `preview delete --skip-confirmation`, lifecycle `add`, `rollback <version-id> --name`, containers list/instances/ssh as ProxyCommand). Migrations stay at the top level; the `previews` block copies vars, containers and Durable Object bindings, and names throwaway D1/R2 resources. Expected `changes()` 3, 2 and 0, and the guard rollback, match `sqlDatabase` / `handleD1Request` (`/exec` always reports `count: 1`; Installation omits D1 `meta.changes`). Only public hostnames already in the repo, and placeholders, appear.
- [x] CHANGELOG: the hand-written bullet is under Maintenance, which is where `release.php` files a `ci(edge)` squash subject. `check-links` passes. Feature commits are Conventional Commits, 45–62 characters; the merge subject is a plain merge.
- [x] Cloudflare phase: storage stays behind `d1.internal` / `r2.internal` and the bindings; there is no per-request global state; hosted DB config is the binding set (DSN remains a self-host path).
- [x] Mutations: 11 of 13 caught (see the report). No new findings from `dcecdde`.

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-1557 | Nit | The unit tests do not pin the full binding list, or that merge then guard then wrangler run in that order inside the step. Dropping the `STORAGE` grep, or running the guard before the merge, is uncaught. The live dry-run still checks the bindings. Fix: assert the six `want` strings and the three-line order. |
| N-1558 | Nit | mergeable_state is dirty: the branch is 1 behind main (`v3.29.0`). The `#252` Unreleased bullet on this branch has already moved into `[3.29.0]`. Merge main in as a plain merge (no rebase) before the doc commit. |
| N-1559 | Nit | The hand-written Maintenance bullet does not match the squash subject, so `release.php` would keep both. Drop it, or make the wording identical. |
| N-1560 | Nit | Cleanup uses `wrangler d1 delete apotheke-api-preview` without `--skip-confirmation` (`-y`), so the prompt can hang. Add `-y`. |

0 High, 0 Medium, 0 Low, 4 Nit.

## Verdict

**PASS** at `dcecdde`, confirmed by the merge-only read at `0d2f99d`. Before the doc commit, merge main in as a plain merge (no rebase). The merge may change only `CHANGELOG.md` and `VERSION`. I check that delta and the re-run CI at the hash check. Squash-merge with the PR title as the subject.
