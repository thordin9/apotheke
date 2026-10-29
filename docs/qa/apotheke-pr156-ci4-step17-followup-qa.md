# QA review: PR #156, step 17 follow-up (#154 findings)

**Head:** `e23f10b5fe6c2f48c18099aa1f1db5d6f5196dcd` on `cursor/ci4-upgrade-guide-l760`, same repo, 8 commits. The
first read was at `07dfafd`.
**Base:** `feature/ci4` `455aef8` (#149, #151 and #155 merged), 0 behind. **CI:** run 40859196670 on that sha,
25/25 check runs successful. **Scope:** docs, plus two lines in `scripts/upgrade-rollback.sh`. Light gate, read from
the diff.

**Verdict: PASS.** All earlier findings are fixed. N-801 is a wording note in the PR. N-860 and N-861 are stale lines
outside the PR.

## Checklist (first read, 07dfafd)

- [x] **L-760:** keep `APP_KEY` / `APP_KEY_FILE` unchanged, with a copy stored apart from the backup. This is
  accurate:
  - the key is read only from the environment (`src/secretbox.php`, `Core\Security\SecretKey`);
  - an `APP_KEY_FILE` inside the data directory or a backup path is refused;
  - so neither the zip nor `./data` holds the key.
- [x] **L-761:** `/originals/x` returning 404 matches `docker/nginx.conf` (`location ~* ^/originals(/|$)`),
  `OriginalsGuard` with its filter, and `docker-checks.sh` lines 23 to 25.
- [x] **N-762:** Rollback A names the 2.x image run before the switch, or a newer one.
- [x] **N-763 (`62ee1b9`):** the check `file mutations undone` (`m0` against `m4u`) runs before the DB mutations
  and the re-restore.
  - The #154 mutant (no `chmod 0640 "$original"`) now fails with `mode originals/….png: 0644, want 0640`.
  - The clean SQLite run reports "all steps equal" and 7 of 7 mutations caught.
- [x] **N-782:** HANDOFF lists #154 as merged at `7ee637b`.

## Checklist (delta, 07dfafd to e23f10b)

- [x] **`4b58844` (merge of `455aef8`):** `--remerge-diff` is empty, so there were no conflicts.
  - HANDOFF item 7 (#151: no gate step `pending`) and item 10 are kept, as are #155's lines.
  - The diff-of-diffs of #156's own changes against `07dfafd` shows only shifted line numbers and context.
- [x] **`4cb9d52`:** the upgrade guide's heading is now "Before 3.0.0: the Control Panel on the CI4 app". The
  matching note is at `control-panel.md` lines 7 and 8.
  - Both match `ci4/app/Config/Routes.php`: the index, every section (show, save, reset), the logo and logo reset,
    `export.json` and import, and autofill token, test and test-photo, all under `settings`. Users & sign-in is
    there too.
  - The anchor resolves, and no link to the old anchor is left.
- [x] **N-800 fixed:** the Rollback A line is wrapped to 113 characters.
- [x] **N-840 fixed (`433d28c`):** the claim is accurate.
  - `bin/backup.php` and `bin/migrate.php` dispatch to spark, as do the `bin/settings.php` settings and account
    forms.
  - The `ci4/spark` pre-boot runs `DataDirOwner::become` for restore, backup, migrate, settings and accounts before
    CodeIgniter boots, or exits 1.
  - `bin/*`, `ci4/spark` and `DataDirOwner` are unchanged from the #155 head read earlier today.
- [x] **N-820 fixed (`e23f10b`):** HANDOFF lists #151 (`77d9170`), #149 (`b38732e`) and #155 (`455aef8`) as merged.
  The CHANGELOG #156 bullet names N-800, N-820 and N-840.
- [x] **CHANGELOG:** released sections from 2.6.26 down are byte-identical to `main`.
- [x] **Links:** `check-links.mjs` gives 246 relative links in 130 files, 0 broken. `release.php check-links` is
  clean.
- [x] **Secrets:**
  - `.gitleaks.toml` is unchanged.
  - gitleaks on `feature/ci4..pr` finds no leaks.
  - The added lines contain only a `127.0.0.1` URL and links to PRs #149, #151, #154, #155 and #156: no secrets,
    hostnames or addresses.

## Findings

**N-800: fixed.** **N-820: fixed.** **N-840: fixed.**

**N-801:** `upgrade-3.0.md` says the Control Panel pages have "the same `settings` permission as 2.x". That is true
for the panel itself. Users & sign-in (`/settings/security`, users, roles, password) needs `users`, in the CI4 routes
and in legacy `route_perm()`. Suggested wording: "the same permissions as 2.x".

**N-860 (outside the PR):** `docs/control-panel.md:135` says the account and sign-in commands "still run on the
legacy code until the CI4 Auth module lands". Since step 7 B, `bin/settings.php` passes users, user, roles, auth and
password to `spark apotheke:accounts`; only `auth status` stays legacy.

**N-861 (outside the PR):** `docs/testing.md:72` says steps marked `pending` list parts from a page CI4 has not
ported (the Control Panel). Since #151 no scenario step sets `pending`, which HANDOFF item 7 also says. The
mechanism remains in `tests/parity/gate.php`.

## Notes

- Mutations were made in scratch clones and reverted.
- No servers were left running. Nothing was pushed except this report.
