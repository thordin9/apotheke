# QA: apotheke #267, note previews_enabled and ssh --stdio in the preview plan

- PR: https://github.com/thordin9/apotheke/pull/267 (DevOps)
- Reviewed head: `e872ebfe5e857859137b23ead9a05378b5e02042`
- Gate: light (diff read, CI, docs only; no suites)
- Reviewer: Apotheke QA 2
- Merge-only read: `16eb158250fcd74251c0ffa5ed74098605ce928f`. It plain-merges main `9bccc634` on top of `d5c117a6` (this doc). 0 behind main. The PR diff against main is still `docs/deployment-and-operations.md` (+9/−5, the same added and removed lines as at the PASS head) plus this doc.

## Checklist

- [x] Head confirmed unchanged at `e872ebfe`. One commit, +9/−5 in `docs/deployment-and-operations.md` only. 0 behind main `4dd89b73`, `mergeable_state` clean.
- [x] CI at this head: 21/21 green (CI workflow, CodeQL, Analyze `actions` and `javascript-typescript`). That includes Tests (PHP 8.4, sqlite and pgsql), Upgrade and rollback on both databases, Visual E2E (3 shards), Docker build/smoke and Compose for both images, Lint, Secret scan, DI, deptrac, Conventional Commits, Headless mobile, and D1 (local workerd).
- [x] Accuracy: `wrangler preview` prints a workers.dev Preview URL only when `previews_enabled` is true. The plan correctly leaves production workers.dev (`enabled`) false, and leaves wrangler `workers_dev` and `preview_urls` off (those wrangler keys are not the same as the Workers script subdomain API fields `enabled` / `previews_enabled`). Cloudflare's custom-domain docs use `previews_enabled` with `enabled: false`. `wrangler containers ssh --stdio` is the documented stdio form; adding it to the OpenSSH `ProxyCommand` is correct.
- [x] No real hostnames, keys or tokens in the delta. Only placeholders already used in the plan: `p4-golive`, `apotheke-api-preview`, `~/.ssh/apotheke-preview`, `cloudchamber@<instance-id>`. Throwaway `APP_KEY` / `APP_PASSWORD` wording is unchanged.
- [x] Commit subject `docs(deploy): note previews_enabled and ssh --stdio in preview plan` is 67 characters and a valid Conventional Commit. No CHANGELOG line (docs-only; expected).

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-1893 | Nit | The plan tells the operator to turn `previews_enabled` on for the check, revert it in clean-up, and read back `{enabled:false, previews_enabled:false}`, but it never names the wrangler or API command to set or GET the script subdomain. Add the set and the GET. |

0 High, 0 Medium, 0 Low, 1 Nit.

## Verdict

**PASS** at `e872ebfe`, confirmed by the merge-only read at `16eb1582`. Squash-merge with the PR title as the subject.
