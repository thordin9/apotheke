# QA review: PR #154, CI4 step 17 docs rewrite

**Head:** `491fae0c0c53ccea3da23e4147b710525048f4a6` (as expected) on `cursor/ci4-step17-docs`, same repo. **Base:**
`feature/ci4` `24830e7`, 0 behind, 9 ahead. **CI:** run 40790885693 on that sha, 25/25 check runs successful.
**Scope:** docs plus test-only changes (LayoutTest, `scripts/upgrade-rollback.sh`, `tests/upgrade/compare.php`), so
this is a light gate. No PG runs or image rebuilds were needed.

**Verdict: PASS.** No H or M findings. L-760 and L-761 are additions to the upgrade guide, best done before step 18
(cutover). N-762 and N-763 are optional.

## Checklist

- [x] **Link check:** `node scripts/check-links.mjs` gives 246 relative links in 126 files, 0 broken (file and anchor
  targets). `php scripts/release.php check-links` is clean (exit 0).
- [x] **`docs/upgrade-3.0.md` read end to end against the code:**
  - The `APOTHEKE_APP` values legacy/ci4/empty, exit 64 on PHP < 8.4, and the `sessions/.ci4` one-time sign-out match
    `docker/entrypoint.sh`.
  - The quoted log line matches entrypoint line 78. `ARG PHP_VERSION=8.4.27` and `CI_ENVIRONMENT=production` match
    the `Dockerfile`. Port 9091 and `/data` match.
  - `docker-compose.yml` does not pass `APOTHEKE_APP`, as the guide says. `-u www-data` (uid 82) is valid.
  - The backup zip holds the kept originals (`src/backup.php`). The `bin/*` routing to `spark apotheke:*` matches the
    code: `settings.php` `auth status` and `key`, and `seed.php`, stay legacy.
  - There is no CI4-only migration (`ci4/app/Database/Migrations` holds only `.gitkeep`). The allowlist has 4 entries.
  - The "What CI proves" text matches the script: seed contents, both cross-restores, 0750/0640 originals, and
    `files/` 0750 under umask 022.
  - VERSION is 2.6.25, and "until 3.0.0, inside the 2.x image" holds.
- [x] **Unsafe steps:** none.
  - No step touches originals outside backup and restore, and the zip carries them byte-identical.
  - The guide keeps port 9091, curls `127.0.0.1`, and keeps the start-up sign-in check. Compose still binds
    `127.0.0.1:9091` (SEC-M3).
  - No step changes `APP_KEY` or stored secrets.
- [x] **Known gap stated:** "Before 3.0.0: not yet on the CI4 app" lists `/settings` and its sections as not ported,
  gives the workaround (2.x app or `bin/settings.php`) and says they are ported before 3.0.0. The note is repeated in
  `control-panel.md`, and HANDOFF item 11 names the port as blocking step 18.
- [x] **Other docs:**
  - The architecture section has 21 modules, which matches `ci4/modules` and the 21 deptrac layers.
  - The request flow, compiled routes and CLI routing match the code.
  - `CI_ENVIRONMENT` defaults to production.
  - The testing, CI, HANDOFF and CHANGELOG updates are consistent with #150.
- [x] **N-640:** the new LayoutTest case checks every menu id per role (admin, manager), one user read, and the user
  passed to every check. Four mutants of `Page::menu()` each fail the test:
  - `allows(null, …)`;
  - `can($p)` (7 reads);
  - export gated on `import`;
  - Users link gated on `backup`.
- [x] **N-641:** the target is `mode files`, which matches `DIFF … mode files: 0700, want 0750`.
  - Mutant A (no `files` mode check in compare.php): the job reports "not caught" and fails.
  - Mutant B (an attachment edit instead of the chmod): the job reports "not caught". The old bare `files` target
    would have matched that line.
- [x] **N-642:**
  - Each mutation is compared against a clean `m0` and undone.
  - `item_tags` is undone by re-restoring the legacy zip.
  - `m0` = `m6` "every mutation undone" (13 tables, 38 files) is checked.
  - The `saved_filters` drop runs last.
  - Mutant D (no re-restore): the `m6` check fails on `item_tags`.
- [x] **N-643:** `UPGRADE_FILES_MODE` is exported and `compare.php` reads it. Mutant E (`0770`) gives 8 mode DIFFs
  and the job fails.
- [x] **Clean run:** `scripts/upgrade-rollback.sh` on SQLite (PHP 8.3.36 and 8.4.27, umask 022) reports
  "all steps equal" and 7 of 7 mutations caught.
- [x] **Secrets and identifiers:**
  - `.gitleaks.toml` is identical to the base.
  - gitleaks on `feature/ci4..HEAD` finds no leaks.
  - The added lines contain only `127.0.0.1` and the PR #154 link: no hostnames, addresses, account ids or Worker
    URLs.
- [x] **Cloudflare phase:** nothing blocks it. The guide states "Docker only" as the supported install; the
  Container phase will extend that page rather than undo anything. The storage notes (`Core\Storage`, files under
  `DATA_DIR`/`ORIGINALS_DIR`) match the current code.

## Findings

**L-760: the guide does not mention `APP_KEY`.**
- By design, neither the zip nor the `./data` copy holds `APP_KEY` / `APP_KEY_FILE` (see `docker-compose.yml` and
  Deployment, "Stored secrets").
- The guide's backup and rollback steps do not say to keep the same key across the switch and both rollbacks, or to
  keep a copy of it with the backup. If the key is missing at restore time, the autofill token counts as unset.
- **Suggestion:** one line in step 1, "Keep your `APP_KEY` / `APP_KEY_FILE` unchanged and stored apart from the
  backup".

**L-761: the post-switch checks leave out the originals check.**
- PLAN §3.6 step 2 also requires `docker-checks.sh` to show `/originals` as 404 ×3.
- The guide's checks after the switch are the log line, `/healthz` and `migrate --status` only. CI covers the 404s,
  but the runbook should let an operator confirm that the photo guarantee still holds on their install.
- **Suggestion:** add a check such as `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:9091/originals/x`,
  which should return 404.

**N-762: Rollback A says only "start the previous 2.x image".**
- PLAN §3.6 and `database.md` name 2.5.x, under the PM L-2 backport rule.
- A 2.x image older than the one that applied the migrations would show unknown migrations. The guide's
  `--status` "nothing unknown" check would catch that, but the guide gives no next step.
- **Suggestion:** "the 2.x image you ran before the switch, or a newer 2.x".

**N-763: the "every mutation undone" check runs after the zip re-restore.**
- Because of that order, it cannot see a missed manual undo. A mutant without `chmod 0640 "$original"` still gives
  "all steps equal".
- Leftovers cannot hide a mutation, because of the N-546 rule that each DIFF/FAIL line must name its target.
- **Optional:** snapshot before the `item_tags` mutation and compare it with `m0`.

## Notes

- Mutations were made in a scratch clone and reverted. `UPGRADE_WORK` dirs were removed by the script.
- No servers were left running. Nothing was pushed except this report.
