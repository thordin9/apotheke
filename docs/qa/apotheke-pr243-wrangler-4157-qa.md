# QA: apotheke #243, wrangler 4.156.0 → 4.157.0 (/edge) plus regenerated worker types

- PR: https://github.com/thordin9/apotheke/pull/243 (Dependabot, with one commit from Coder)
- Reviewed head: `923755a016935c9a01b4b7de23b0326e3c06ac55` (base `0322c48` = main)
- Gate: light (diff read and CI, dependency-only change)
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope: 4 files, all under `edge/`: `package.json`, `package-lock.json`, `worker-configuration.d.ts` and `app/worker-configuration.d.ts`. No app, worker or workflow source changes.
- [x] `package.json`: only `wrangler` 4.156.0 → 4.157.0 changes; `typescript` stays pinned.
- [x] Lockfile: wrangler, workerd 1.20261103.1 (plus 5 platform binaries) and miniflare 5.20261103.0-beta all move together. Transitive: semver 7.8.6, tslib 2.9.1 and the new `@cspotcode/source-map-support` dependency of wrangler. The other changes are npm's `libc` metadata on optional `sharp` and `@img/sharp-*` binaries.
- [x] Integrity: the wrangler, workerd, workerd-linux-64, miniflare, semver and tslib sha512 values match the npm registry.
- [x] Regenerated types (`923755a`, parent `2b9a3d0`): workerd version header; `ExecutionContext.mapVirtualHost`; `ContainerStartupOptions.enableInternet`, `TailStream.ScriptVersion.id` and `Onset.attributes` become optional; `streamDiagEvent` handler added (`StreamDiagnostic` is defined in both files). Env binding blocks and config hashes are unchanged.
- [x] CI on head: 23 checks green, including Check and dry run (apotheke-api), Build and check (getapotheke-app), D1 (local workerd), Secret scan, DI rule, Conventional Commits and CodeQL. Both deploy jobs were skipped as expected; mergeable_state is clean.
- [x] CHANGELOG: release.php generates the entry from the `ci:` squash subject (it goes under Maintenance); no hand-written bullet is needed.

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-1556 | Nit | The `edge/app/worker-configuration.d.ts` header now reads `wrangler types --config=wrangler.jsonc` because of the command used to regenerate it. The comment changes and the hash does not, so this is harmless. No action needed. |

0 High, 0 Medium, 0 Low, 1 Nit.

## Verdict

**PASS** at `923755a`.
