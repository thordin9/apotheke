# QA review: PR #159, CI4 spark seed, auth status and key rotate

**Head:** `1cb81d29008b2d53a83679eefd279dc1b88182bf` on `cursor/ci4-cli-port-seed-auth`, same repo, 10 commits.
**Base:** `feature/ci4` `57d926d`, 0 behind. **CI:** run 40899508049 on that sha, 25/25 on attempt 2.
**Scope:** full gate at `fdaf239` against `35cb0e6`; merge-only read of `1cb81d2` (feature/ci4 with #158). The #157
part was reviewed separately.

**Verdict: PASS.** No H, M or L findings; five N notes.

## Checklist

- [x] **Merge `5446645` of `35cb0e6`:** plain merge. `--remerge-diff` shows only the CHANGELOG and HANDOFF conflict
  resolutions (both lanes kept). Nothing extra came in.
- [x] **Merge `1cb81d2` of `57d926d` (#158), merge-only read:** plain merge. `--remerge-diff` shows only append-only
  CHANGELOG and HANDOFF resolutions. Both sides changed only CHANGELOG, HANDOFF and `docs/architecture.md` (separate
  hunks, auto-merge sane). The delta equals #158 outside HANDOFF, CHANGELOG and `docs/qa`; released CHANGELOG sections
  match `main`; `.gitleaks.toml` unchanged; no Routes or Config change. CI attempt 1 failed only the SQLite parity
  timing budget (catalog +3.35 ms, items +3.08 ms, 0 differences); the delta does not touch the web path, so a flake.
- [x] **`bin/settings.php`:** only the `$ci4Form` arms and the comment changed; #157's `if` body is untouched.
  `key generate` is still answered first (L-680). `key rotate` goes to `apotheke:settings`, `auth status` to
  `apotheke:accounts`.
- [x] **Parity with legacy:**
  - `appPasswordProblem()` matches legacy `app_password_problem()` line for line: null character, `PASSWORD_MIN` 8,
    the same messages.
  - `AuthStatus::onAtStart()` matches `auth_on_at_start()`; the exit codes (0, 3, 4, 1) match.
  - `rotateSecrets()` matches `secret_rotate()`.
  - `DemoItems` matches `seed_demo()`.
- [x] **CI4 suite, SQLite (PHP 8.4.27):** 897 tests OK (44 skipped, 1 incomplete).
- [x] **CI4 suite, PostgreSQL 17:** fresh role and database (`qa159_*`), 897 tests OK (6 skipped). The two local
  failures Coder reported do not reproduce with a role and password of its own, which is consistent with the stated
  password collision.
- [x] **Parity gate (15 rounds):** 223 steps in 15 areas, 0 differences, none over budget, on both SQLite and
  PostgreSQL.
- [x] **Static checks:** `spark apotheke:routes --check` is up to date. deptrac reports 0 violations and the DI rule
  is OK. `lint.sh` and `check-commits.sh` (8 commits) pass.
- [x] **Command parity, CI4 against legacy:** fresh SQLite installs, APP_PASSWORD short, valid and empty; `auth status`,
  `seed --demo`, `migrate`, `settings list` and `users`.
  - Exit codes and messages match.
  - The one known difference: a short APP_PASSWORD makes CI4 seed exit 1 with the message, where legacy ends in a
    fatal error with exit 255.
- [x] **seed owner check (`bin/seed.php`), CI4:**
  - Non-root: runs.
  - Root, folder owned by the user: switches to that user; files belong to the owner.
  - Root, root-group folder: switches to the owner's group.
  - Root, root-owned or missing folder: exit 1, nothing created, same message as legacy.
- [x] **Cloudflare phase:** nothing blocks it.
  - The new code is CLI-only (spark commands and the migration seed check).
  - `DemoItems` stores photos through `ImageStore`.
  - No new per-request static state.
- [x] **gitleaks 8.30.1** (the CI-pinned version): `--log-opts=--all` over 1,461 commits and the PR range, no leaks.
  `.gitleaks.toml` is unchanged.
  - The added lines contain only `127.0.0.1` and links to #159.
  - `tests/` and `ci4/tests/` are excluded from the image (`.dockerignore`).

## Security

- **Canary APP_PASSWORD** (short and valid values):
  - Never seen in stdout, stderr, the `execve` arguments of every process (`strace -f`, including the spark child),
    data-folder files including the SQLite database, or `ci4/writable`.
  - Covered paths: seed, migrate, first admin, settings seeding (`list`), auth status, key rotate.
  - Web start with a short APP_PASSWORD: CI4 answers a generic 500, as legacy does. The log line holds the message
    only.
- **`auth status` is read-only:**
  - On an older install (one migration row removed), `schema_migrations` is unchanged afterwards and the database
    hash is identical.
  - Its file activity matches legacy exactly: the SQLite file is opened, and the `-wal`/`-shm` files are created and
    removed. There are no writes under `ci4/writable`.
  - A new install gets no `catalog.sqlite`.
  - With the database down it exits 1 with the sanitized line, without the DB password.
- **Docker entrypoint:** only a comment changed. The wait loop still accepts only 0 (on); 3, 4 and anything else
  stop with 78 after the retries. `docker-compose.yml` is unchanged, so SEC-M3 (localhost-only by default, sign-in
  required) holds.
- **`key rotate`:**
  - Plaintext is encrypted, and a sealed value is rotated.
  - The new key decrypts the result; the old one no longer does.
  - With the wrong current key, a malformed new key or a key on argv: exit 1, row unchanged, same messages as
    legacy.
  - Keys and the token value are never printed.
  - It writes in one transaction (`SettingsRepository::rewrite()`) and creates no files.

**Mutations: 13 made, 12 killed.**

| ID | Mutation | Result |
|---|---|---|
| M1 | Length off-by-one | killed |
| M2 | Null-character check removed | killed |
| M3 | Value added to the message | killed |
| M4 | 4-only-on-a-new-install guard removed | killed |
| M5 | SQLite `is_file` guard removed | killed |
| M6 | Migrating connection | killed |
| M7 | Decrypt failure does not stop | killed |
| M8 | Sealed with the old key | killed |
| M9 | Migrate seed check removed | killed |
| M10 | `list` first admin skipped | killed |
| M11 | seed owner check removed | killed |
| M12 | Key on argv accepted | killed |
| M13 | `#[\SensitiveParameter]` removed from `appPasswordProblem()` | survived (N-920) |

## Findings

**N-920:** no test pins `#[\SensitiveParameter]` on `Accounts::appPasswordProblem()` (M13 survived). In the image,
`zend.exception_ignore_args=On` already keeps arguments out of traces. Suggestion: a small reflection test for the
sensitive parameters this PR adds.

**N-921:** `SecretBox::sealWith()` and `openWith()` (from before this PR, now called by `key rotate`) take the plain
value and the key without `#[\SensitiveParameter]`. The same image setting applies, so this is hardening only.

**N-922:** `seed --demo` adds the eight demo items, with photos, to an existing install without asking, as legacy
does (items with the same name are skipped). This is noted as unchanged behaviour, not a regression.

**N-923:** on a new data folder, `auth status` creates the empty `images/`, `thumbs/`, `originals/` (with its
`.htaccess`) and `sessions/` folders, as legacy does. No database file is created. The Docker entrypoint creates
these folders anyway.

**N-924:** the known difference above (CI4 seed exits 1 with the message, legacy exits 255 with a fatal error) is not
listed among the documented deviations. Optional: one line in the CHANGELOG bullet.

## Notes

- N-920 to N-924 go to the step 18 PR.
- Mutations were made in a scratch clone and reverted.
- Test servers were stopped. The `qa159_*` role and database were dropped, and scratch data was removed.
- Nothing was pushed except this report.
