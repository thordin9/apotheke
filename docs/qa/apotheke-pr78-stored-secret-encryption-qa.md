# QA: apotheke PR #78, S-L5 stored-secret encryption at rest (main)

- **PR:** [#78](https://github.com/thordin9/apotheke/pull/78), "fix(security): encrypt stored secrets when APP_KEY is set", into `main`, by Coder. Hardening for S-L5 from the autofill-integration audit (`/workspace/qa-docs/security-audit-apotheke-autofill-integration-2026-10-24.md`).
- **Full review at:** `2f090714a2b0c0cf0e65f0e223eb1a0226c3270d` (6 commits on v2.6.7 `c4e9eda`).
- **First verdict:** FAIL at `8612d3d65ba3ce50f87975f62ae425df10f2b4b9` (§1–§16, kept as the record). The delta `2f09071` → `8612d3d` is in §11.
- **Re-check head (final):** `21f84323eff716f3d613c6ea0536dfba448c7d7b`, verified through the API. It's 5 commits on `8612d3d`: the v2.6.9 merge `8467fb3`, `e62bfce` (the M-1 and L-1 fix), `7cda2ae` (tests), `fa14576` (docs) and `21f8432` (docs only). The base is `main` `3f729a3` (v2.6.9). The code was tested at `fa14576`, and `21f8432` changes only comments and docs (§17).
- **CI:** run 40095979294 at `2f09071` and run 40097612637 at `8612d3d` were 10/10 `success`. Run 40101120543 at `fa14576` was **10/10 `success`** (17:07–17:16 Athens time, UTC+3). Run 40102018974 at `21f8432` was **10/10 `success`** (17:20–17:30 Athens time, UTC+3).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only. I made no comment, review, push or merge. Sources: codeload tarballs of `2f09071`, `8612d3d`, `9d4c9cc`, `2c375ca`, `4dd79fe`, `c4e9eda` and `d821bb8`, plus `api.github.com`. Local runs used scratch copies on ports 9781–9788, PG databases `qa2_78_*`, and temp SQLite `DATA_DIR`s.
- **Verdict at the re-check head: PASS (0 High, 0 Medium, 1 Low, 9 Nit, 10 Info).** **M-1 is resolved**: a configured but unusable key now refuses secret saves with a 422 and a named cause, on every writer, and no key still stores warned plaintext. **L-1 is resolved**. **L-3** (raised in this re-check against the `fa14576` permissions advice) is resolved by `21f8432`. L-2, N-1 to N-6 and I-1 stay open as follow-ups, and the re-check adds N-7 to N-9 and I-8 to I-10 (§17).
- The first round's verdict was FAIL (0 High, 1 Medium, 2 Low, 6 Nit, 7 Info) at `8612d3d`, blocked by M-1.

## 1. Environment and method

- No Docker on the box. PHP **8.3.36** and **8.4.27** (box CLIs, with sodium compiled in), and PostgreSQL **17** on 127.0.0.1:5432 with the test superuser `apotheke` (`<CI test password>`).
- I used the `php:8.3.36-fpm-alpine` rootfs (`/tmp/qa-img.rEFJ/php83`) read-only for the image checks: `php -m`, the Dockerfile line, and a sodium-less run through my own overlay, which I removed afterwards. The rootfs was left unchanged.
- Probes: a PHP probe for crypto, tamper, key decode, the key file, import, export and backup (77 checks, run on SQLite). An HTTP probe against `php -S` with sign-in on and the autofill stub (15 checks). A CLI rotation probe on 8.3/8.4 × SQLite/PG17 (16 checks per leg). 30 mutations. gitleaks 8.30.1. Logs are in `/workspace/qa2-logs/pr78/`.

## 2. CI and commits

- Run 40095979294 (`2f09071`, `pull_request`): Lint, Headless mobile checks, Docker build + smoke test, Visual E2E 1/3–3/3, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Conventional Commits and Secret scan were all `success`.
- Every non-merge subject passes `scripts/check-commits.sh --message`: `fix(security):`, `docs(changelog):`, `test(visual):`, and on the delta `chore:`, `ci:`, `docs:`.

**PASS**

## 3. Suites

| Leg | Result at `2f09071` |
|---|---|
| PHP 8.3, SQLite | OK, 443 tests, 3206 assertions, 13 skipped |
| PHP 8.3, PG17 (`qa2_78_h83`) | OK, 443 tests, 3234 assertions, 3 skipped |
| PHP 8.4, SQLite | OK, 443 tests, 3206 assertions, 13 skipped |
| PHP 8.4, PG17 (`qa2_78_h84`) | OK, 443 tests, 3234 assertions, 3 skipped |

- Each leg also passed the CLI migrate, idempotent re-run and seed (`CLI_OK`). The runs include the unit and HTTP integration suites.
- The skips are environmental: PG-only tests on SQLite, a HEIC case, and the php-cgi dev-server test.
- `SecretAtRestTest` passes its 17 tests on every leg.
- At `8612d3d`, PHP 8.4 SQLite was OK with 448 tests, 3247 assertions and 13 skipped (#79's tests arrive with v2.6.8).

**PASS**

## 4. Crypto (claims 1 and 2)

- **Envelope:** `enc:v1:` + base64(24-byte nonce ‖ `sodium_crypto_secretbox` ciphertext with its 16-byte tag). The length is exactly 24 + 16 + the plaintext length, and raw `sodium_crypto_secretbox_open` with the key returns the plaintext. This matches `docs/configuration.md` ("Envelope").
- **Nonce:** `random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)` on every seal. 2,000 seals of the same plaintext gave 2,000 distinct envelopes and 2,000 distinct nonces. Each save writes a fresh envelope, so there's no deterministic output and no nonce reuse.
- **Key decode:** strict standard base64 of exactly 32 bytes, with an optional `base64:` prefix and surrounding whitespace trimmed.
  - Refused: 31, 33 and 64 bytes, 64-char hex, raw 32 binary bytes, URL-safe base64, a passphrase, empty, and `base64:` alone.
  - A fuzz of 3,000 random strings accepted nothing that wasn't 32 bytes.
  - Nothing is padded, truncated or derived (no hashing of passphrases). Unpadded base64 of 32 bytes is accepted, which is harmless (I-2).
- **Scope:** `secret_at_rest_names()` is `['autofill_token']`. `password_hash` stays a hash, and users' hashes are untouched.
- **Memory:** there's no `sodium_memzero` (I-3).

**PASS**

## 5. Tamper and fail-closed

With the right key, each of these is read as **unset**, with no exception or 500 and the stored row left unchanged:

- a flipped bit in the nonce, the tag or the ciphertext (8 positions);
- truncation by 1 byte, to 40 bytes or to 30 bytes, or of the base64 text;
- invalid base64 characters, an empty body, appended bytes, or a swapped nonce;
- non-string JSON (array, number, bool, null).

The log line names only the setting ("could not be decrypted and is treated as unset"), and the HTTP response is the same as for "no token set", so there's no oracle.

- **Wrong key:** the token is unset, `autofill_token()` is `''`, the row is unchanged, and no bearer is sent (the stub got no `Authorization` header and Test connection said 401). The page shows "No token set." and no 500.
- **Exception, L-2:** a value that starts with `enc:` but isn't exactly `enc:v1:` is treated as **legacy plaintext**. That covers `enc:v2:`, `ENC:V1:`, a leading space, `enc:v0:` and bare `enc:`. The value is used verbatim as the bearer and re-sealed into a v1 envelope on the next read.
- Only someone with DB write access, or a future envelope version, can produce such a value, because import, restore and the CLI can't write the token (§6). So it isn't a confidentiality issue, but it doesn't fail closed and it would irreversibly wrap a future `v2` value after a downgrade. Mutation M05 survives for the same reason.
- Whitespace inside the base64 is ignored by PHP's strict decoder, and the MAC still authenticates the decoded bytes (I-2).

**PASS with L-2**

## 6. Downgrade, import, restore and export

- **Legacy plaintext:** a pre-upgrade plaintext row is read normally and **re-sealed on the next read** once a key is set. It's also re-sealed on the next save, and `key rotate` re-encrypts it (`encrypted 1`).
- **Injection paths:** `settings_import` (JSON and the CLI) ignores `autofill_token` in plaintext, envelope, case and space variants ("ignored", 0 applied). Restore goes through the same filtered import, so a crafted `backup.json` `settings.autofill_token` is not written. CLI `set autofill_token` is refused ("Unknown setting"). So a plaintext value can't be injected through import, restore or the CLI. Only direct DB writes can do it (see L-2).
- **Export and backup:** `settings_export()`, `/settings/export.json`, CLI `export` and `backup.zip` contain no token, no envelope, no `autofill_token` name and no key.
- **Cross-key restore:** because backups never carry the secret, a restore into an install with a different key leaves that install's own token as it was. Nothing undecryptable comes in.

**PASS**

## 7. The key can't end up in DATA_DIR or a backup (PM request)

| Check | Result |
|---|---|
| `APP_KEY_FILE` = `DATA_DIR/app.key`, `DATA_DIR/sub/deep/app.key`, `DATA_DIR` itself, `DATA_DIR/`, `DATA_DIR//app.key`, `DATA_DIR/./app.key` | **refused** (logged, no key used) |
| `DATA_DIR/x/../app.key` (x missing), `outside/../<data>/app.key` | **refused** |
| `ORIGINALS_DIR/…` (the other backup root) | **refused** |
| Symlink outside → a file inside `DATA_DIR`; a path through a symlinked dir that resolves into `DATA_DIR`; relative path with cwd = `DATA_DIR` | **refused** (realpath of the file and the parent) |
| A file outside, 0600 | used |
| `APP_KEY` and a valid file both set | `APP_KEY` wins. An invalid `APP_KEY` does **not** fall through to the file (documented) |
| Missing file, a directory, a short key | no key, no exception, distinct log line |
| `backup_create()` with `app.key` in `DATA_DIR`, in `files/` and in `DATA_DIR/keys/` | the zip holds only `backup.json` plus catalog files added by name. No key text or entry |
| Restore of a zip carrying an `app.key` entry | refused: "Unexpected file in backup: app.key" (the entry allow-list) |
| Restore overwriting a key | impossible: restore writes only allow-listed catalog paths under `DATA_DIR`, never env, and key files are refused there anyway |
| Compose defaults | `APP_KEY: ${APP_KEY:-}` comes from `.env` next to the compose file, not `./data`, and `.env` is git-ignored. `APP_KEY_FILE` is empty by default. At `8612d3d` the commented examples mount `./secrets/app_key` read-only at `/run/secrets/app_key` (bind mount or Compose secret), and both are outside `./data` (`/data` in the container). |

- The key is never written to the DB, `config.php`, a backup or an export.
- Remaining gaps: the key file's permissions aren't checked (N-1), and `secrets/` isn't git-ignored (N-4).

**PASS**

## 8. Rotation (claim 3), end to end on 8.3/8.4 × SQLite/PG17

All 16 checks passed on all 4 legs, apart from one harness artifact on SQLite (a read-only WAL left from my own read-only DB test), which I re-ran by hand and which passes.

- `key generate` prints base64 of 32 bytes and stores nothing. Two runs give different keys.
- Rotating K1→K2 from `APP_KEY_NEW` exits 0 and prints only `rotated 1, encrypted 0`. Afterwards K2 opens the token and K1 doesn't.
- From stdin (with a `base64:` prefix and a trailing newline) it works. Rotating to the same key works with a fresh nonce.
- **Wrong old key, or no old key with an envelope stored:** exit 1, "autofill_token could not be decrypted. Nothing was changed.", and the row is byte-identical. Rotation can't corrupt data with a wrong old key.
- **Invalid new key** (short, 31 bytes, hex or empty): exit 1, "The new key must be base64 of 32 bytes.", unchanged, and the value isn't echoed.
- **Key on argv:** refused ("not read from the command line"), and not echoed.
- **Failure midway:** a write failure (read-only DB) leaves the row unchanged, because the plan is computed first and written in one `db_tx`.
- No token or key appears in stdout or stderr on any path. With PHP's built-in defaults, the uncaught-exception trace can show a 15-char prefix of the new key (N-2).
- Env var name: the code and docs consistently use **`APP_KEY_NEW`**. Coder's claim said `APP_NEW_KEY` (I-1).
- During the documented window between `rotate` and the restart, the running app reads the token as unset. See L-1 for why that window matters.

**PASS**

## 9. Leakage, permissions and sodium

- **HTTP (sign-in on, APP_KEY set, live stub):** saving the address plus token, and the token-only form, both store a fresh envelope. Test connection sends the decrypted bearer, and the stub accepted it.
- I checked 11 responses for the token, the key and `enc:v1:` in body and headers (the URL's own text excluded): `/settings/autofill`, `/settings`, `/settings/system`, `/settings/security`, `/settings/data`, `/settings/export.json`, `/health`, `/healthz`, `/backup.zip`, a 404 page, and the `token-saved` page. None contained any of them.
- Server logs contain no token or key, only the three fixed `apotheke:` lines. The #69/#72 redaction holds.
- **Who can change the token:** `/settings/autofill/token` needs `settings` and CSRF. A viewer gets 403 on set and clear, an anonymous request is redirected, and the row is unchanged. The key commands exist only in the CLI (shell in the container), which is unchanged from #77's model. #80 (`session_idle_hours`) doesn't touch this. CI4 (#69/#76) needs to port the envelope (I-5).
- **php-fpm:** `clear_env = no` (existing), so `APP_KEY` reaches the workers. Workers run as `www-data` (uid 82), which matters for key-file permissions (M-1, N-1).
- **Sodium in the image:** `php:8.3.36-fpm-alpine` builds sodium as `--with-sodium=shared` and enables it through `docker-php-ext-sodium.ini`. `php -m` lists `sodium` on 8.3 and 8.4. `composer.json` adds `ext-sodium`, and CI's setup-php lists `sodium`.
- **Without sodium** (image rootfs with the sodium ini removed):
  - The app still runs. `secret_key()` returns null, new saves are stored **plaintext**, existing envelopes read as unset, and the Control Panel warning shows.
  - The messages are misleading. The log says "APP_KEY is not a 32-byte key" and then "ext-sodium is missing", the panel says "Set APP_KEY" although it's set, and `key rotate` says "The new key must be base64 of 32 bytes."
  - The same plaintext fallback happens with a malformed `APP_KEY` and with an unreadable or refused `APP_KEY_FILE` (§5, §7). The only signal is a generic warning that contradicts the configured key, so a configured key fails open (M-1). The image now asserts sodium at build time (§11).

**FAIL (M-1)**

## 10. Findings (first round, at `8612d3d`; current status in §17.8)

| Id | Sev | Finding | Recommendation |
|---|---|---|---|
| **M-1** | **Medium** | **Hardening (S-L5 / unusable key fails open):** when `APP_KEY` or `APP_KEY_FILE` is configured but unusable, new secret saves **silently fall back to plaintext**. That covers a malformed `APP_KEY`, an unreadable or refused `APP_KEY_FILE`, and a missing sodium extension. The only warning is a generic "Set APP_KEY", which contradicts what the operator just did, and some log lines mislead (§9). The documented `chmod 600` advice makes this a likely Docker outcome, because php-fpm runs as `www-data` and can't read a root-only file or secret. So the feature's core promise (encrypted at rest once a key is set) fails open in a documented setup. | **Required:** once `APP_KEY` or `APP_KEY_FILE` is set but unusable, refuse to store secrets. Fail closed with a clear, specific Control Panel error and a log line that names the cause (malformed key, unreadable file, refused path, sodium missing) and never includes the key. Correct the permissions advice so the file is readable by the app user (for example 0440 root:www-data, or the Compose secrets default). Keep today's warned plaintext when no key is configured, including when sodium is missing. Add a test for each unusable-key case. |
| **L-1** | Low | **Hardening (S-L5 / token-follows-address):** while the key is missing or wrong, the stored envelope reads as unset, so `autofill_drop_token_if_moved()` skips clearing it when a `settings` user changes the service address. When the right key returns, the old token is live again for the **new** address. Confirmed over HTTP for both a wrong key and no key. This regresses the rule that the token never follows the address. It needs a key outage (or the rotate-to-restart window) plus an address change. | Decide "token present" from the stored row being non-empty, not from the decrypted value, and add a test for the wrong-key and no-key cases. Recommended in this PR (one line), but it doesn't block. |
| **L-2** | Low | **Hardening (S-L5 / envelope versioning):** values starting with `enc:` but not exactly `enc:v1:` (`enc:v2:`, case or space variants) are treated as legacy plaintext, used as the bearer, and re-sealed on read. They don't fail closed, and a future v2 value would be irreversibly wrapped after a downgrade. Only DB writers or a future version can produce one. | Treat any `enc:` prefix (case-insensitive, after trim) as an envelope. Read an unknown version as unset, and never re-seal it. Add tamper and prefix tests. |
| N-1 | Nit | The key file's permissions aren't checked. A 0666 file is used without a warning. | Warn on group- or world-writable files (and world-readable ones). |
| N-2 | Nit | Key and plaintext parameters (`secret_rotate`, `secret_key_decode`, `secret_seal_with`, `secret_open_with`) lack `#[\SensitiveParameter]`. With PHP's built-in defaults (`zend.exception_ignore_args=Off`, 15-char params), an uncaught DB error during rotation prints a 15-char prefix of the new key in the trace. Docker isn't affected (`exception_ignore_args=On`). | Add `#[\SensitiveParameter]`. Catch `PDOException` in `key rotate` with a fixed message. |
| N-3 | Nit | The docs' rotation examples put the key on the `docker compose exec -e APP_KEY_NEW='…'` argv (visible in `ps` on the host) and in shell history. | Use `-e APP_KEY_NEW` (pass-through), or pipe into `docker compose exec -T … key rotate` from a file. |
| N-4 | Nit | The Compose example points at `./secrets/app_key` next to the compose file, but `secrets/` isn't in `.gitignore`. | Add `secrets/` to `.gitignore`. |
| N-5 | Nit (pre-existing) | `bin/settings.php get autofill_token` prints the token (now decrypted), and `get password_hash` prints the hash. The audit's "CLI get refuses secrets" row is inaccurate. It's shell-only, so the impact is low. | Refuse `secret`/`internal` keys in `get`, as `set` does. |
| N-6 | Nit | Test gaps (mutation survivors, §12): key length, strictness and derivation, tamper and unknown prefix, no-key open, symlink resolution, and the import guard (unit level). The behaviour is correct by my probes. | Add unit cases for each. |
| I-1 | Info | Coder's claim says `APP_NEW_KEY`, but the code and docs use `APP_KEY_NEW` consistently. | — |
| I-2 | Info | Unpadded base64 keys are accepted (still exactly 32 bytes), and URL-safe keys are refused. Whitespace inside an envelope's base64 is ignored by PHP, and the MAC still holds. A CI4 port should match. | — |
| I-3 | Info | No `sodium_memzero`. That's of limited value with PHP strings. | Optional. |
| I-4 | Info | `backup_drop_key_entries()` is defensive dead code. Backups add entries by name only, and restore's allow-list already refuses `app.key` (M30 survives as an equivalent mutation). | — |
| I-5 | Info | CI4 (#69 autofill module on feature/ci4) reads `autofill_token`. After the next feature/ci4 sync it must open `enc:v1:` per `docs/configuration.md`, or it will send the envelope text as the bearer. That's Coder2's lane. | Port with the same key rules and tests. |
| I-6 | Info | Between `key rotate` and the restart, the running app reads the token as unset (documented sequence). It interacts with L-1. | Docs could say to stop the app or avoid settings changes during rotation. |
| I-7 | Info | `APP_KEY` sits in the container env (`docker inspect`, worker env, because `clear_env=no`). That's standard. `APP_KEY_FILE` is the alternative. | — |

At `8612d3d` there was one Medium (M-1), so that verdict was **FAIL**. The table below is the first round's.

| Severity | Count | IDs |
|---|---|---|
| High | 0 | — |
| Medium | 1 | M-1 |
| Low | 2 | L-1, L-2 |
| Nit | 6 | N-1 to N-6 |
| Info | 7 | I-1 to I-7 |

## 11. Delta `2f09071` → `8612d3d` (v2.6.8 merge and the sodium check)

- **`9d4c9cc` (chore: merge main v2.6.8):**
  - I rebuilt the merge of `2f09071` and `d821bb8` (base `c4e9eda`) locally. **Only `CHANGELOG.md` conflicts.** Every other file in `9d4c9cc` is byte-identical to the clean auto-merge, including #79's `src/{autofill,image-color,items,users}.php` and tests, with no #78 code touched. So per the compute rule this was a CHANGELOG/doc check.
  - `[2.6.8]` to EOF is **byte-identical** to `main` `d821bb8`. `[Unreleased]` holds only #78's linked Fixed bullet.
  - The full suite on PHP 8.4 SQLite at `8612d3d` passes (448 tests).
- **`184c0ef` (ci: assert the sodium extension in the image):**
  - It adds `php -m | grep -qx sodium;` to the first `RUN set -eux; …` chain, right after the imagick checks.
  - Statically: the step runs under `set -e`, with no `|| true`, and `grep` is the last command of the pipeline, so its status is the step's status. Busybox `grep` supports `-qx`, and `php -m` prints `sodium` on its own line.
  - In the image rootfs, the same sequence runs on with sodium enabled. With the sodium ini removed, the shell stops at that line with **exit 1**, so the build would fail.
  - Later layers only add `zz-apotheke.ini`, which has no extension lines, and FPM shares the conf.d, so the check covers the runtime too. This closes Coder's stated risk.
- **`8612d3d` (docs: show APP_KEY_FILE mount and key backup advice):**
  - Compose gains commented examples: a `./secrets/app_key:/run/secrets/app_key:ro` bind mount, or a Compose `secrets:` entry with `file: ./secrets/app_key`, plus the `APP_KEY_FILE=/run/secrets/app_key` hint. Both are outside `./data` (`/data`), and `/run/secrets` is never inside `DATA_DIR` or a backup root.
  - `deployment-and-operations.md` adds "If the key is lost, stored secrets count as unset, so keep the key out of the data volume and its backups."
  - Accurate. N-4 (git-ignore `secrets/`) and the file-permission advice in M-1 apply here.
- **CI 40097612637 at `8612d3d`:** 10/10 `success`.

**PASS**

## 12. Mutations (30, unit filter `SecretAtRestTest|SettingsTest|BackupTest`, PHP 8.4 SQLite)

**17 of 30 killed.** Each file was restored after each mutation, and the workdir was confirmed identical to the head afterwards.

- **Killed:**
  - fixed or deterministic nonce (M01, M02);
  - envelope prefix dropped or mis-stripped (M03, M04);
  - MAC failure returns the stored text (M10);
  - `APP_KEY_FILE` before `APP_KEY` (M14);
  - data-dir check off, or the `DATA_DIR` root dropped (M16, M17);
  - rotation skipping, plaintext-resealing or new-key-decrypting an undecryptable value, or taking the key from argv (M19–M22);
  - no seal on save, no upgrade on read, `password_hash` in scope (M25–M27);
  - warning never shown, or log-once removed (M28, M29).
- **Survived** (the behaviour is correct by my probes, so these are test gaps, N-6):
  - any `enc:` prefix accepted as v1 (M05, the L-2 area; it also survived the HTTP suite);
  - key accepted at ≥32 bytes and truncated, NUL-padded short keys, non-strict base64, weak sha256 derivation (M06–M09);
  - exceptions, missing key or bad base64 returning the stored text (M11–M13);
  - invalid `APP_KEY` falling through to the file (M15);
  - no realpath for symlinks (M18);
  - non-transactional rotate writes (M23, near-equivalent inside `db_tx`);
  - secrets importable (M24, the import guard has no unit test);
  - the backup key-entry drop removed (M30, equivalent).

## 13. Docs, merge integrity and gitleaks

- **v2.6.7 merge `2f09071`:** I rebuilt the merge of `2c375ca` and `c4e9eda` (base `4dd79fe`). It conflicts exactly in `README.md`, `docker-compose.yml`, `docs/deployment-and-operations.md` and `docs/getting-started.md`, as Coder said. Every other file is byte-identical to the auto-merge.
- Each resolution keeps both sides, with main's newer wording plus #78's additions:
  - the README `APP_PASSWORD` row is main's;
  - the "keep sign-in on" line is main's;
  - the getting-started "compose file passes …" list has both `ALLOW_NO_LOGIN` and `APP_KEY`/`APP_KEY_FILE`;
  - `docker run` keeps main's `127.0.0.1` bind and `APP_PASSWORD` and adds `APP_KEY`;
  - Compose has main's localhost port, `APP_PASSWORD`/`ALLOW_NO_LOGIN`, and `APP_KEY`/`APP_KEY_FILE`.
- `[2.6.7]` to EOF matched `main` byte for byte, with #78's linked bullet under `[Unreleased]`.
- **Docs:** `configuration.md` "Stored secrets" (envelope, key, no key, wrong key, rotation) matches the code. Exceptions: the "chmod 600" advice (M-1/N-1), and the Docker rotate example (N-3).
  - There's no separate upgrade note. The CHANGELOG bullet says secrets stay plaintext without a key and the panel says to set one, which is enough for an optional feature.
  - `check-links`: exit 0. `check-links.mjs`: 216 links in 16 files, 0 broken.
- **gitleaks 8.30.1:** the 27 changed text files and the whole tree (repo config) show **no leaks**. There are no hard-coded `APP_KEY` fixtures: the tests generate keys with `random_bytes(32)` at runtime, and `.env.example` has an empty `APP_KEY=`. No 44-char base64 literal appears in the diff. Known fakes only.

**PASS**

## 14. Secrets in this doc

No real key, token or password appears here. Keys are written as `<APP_KEY>` or not at all, and the PG password as `<CI test password>`. The findings are worded as hardening with S-L5 references, with no step-by-step reproduction.

## 15. First-round verdict (superseded by §17.9)

**FAIL at `8612d3d65ba3ce50f87975f62ae425df10f2b4b9`** (full review at `2f09071`, delta diff-read and checked). 0 High, 1 Medium (M-1), 2 Low (L-1, L-2), 6 Nit, 7 Info. CI was 10/10 at both heads. All suites are green on 8.3 and 8.4 × SQLite and PG17. 17 of 30 mutations were killed, and the survivors are test gaps whose behaviour I verified directly.

- **Blocking:** M-1. A configured but unusable key must fail closed instead of storing secrets as plaintext, and the key-file permissions advice must be corrected.
- **Recommended in this PR, not blocking:** L-1 (one line).
- **Follow-up:** L-2 and the Nits.

## 16. Re-check plan (carried out in §17)

Per the compute rule, re-verify only the fix delta from `8612d3d`:

1. **M-1:** diff-read the change. Check that, for a malformed `APP_KEY`, an unreadable `APP_KEY_FILE`, a refused (in-data-dir) `APP_KEY_FILE` and sodium missing with a key set, a secret save is refused (no plaintext row is written) with a specific Control Panel error and log line that never includes the key. Check that no key configured still stores warned plaintext, sodium missing included. Confirm the new tests cover each case, and that each test fails when its guard is reverted. Read the corrected permissions advice in the docs and Compose comments.
2. **L-1, if included:** a wrong or missing key plus an address change drops the stored token, and its test fails when the guard is reverted.
3. CI 10/10 on the new head, the CHANGELOG from the latest release to EOF matching main, and gitleaks on the changed files.

## 17. Re-check at `fa14576` (code) and `21f8432` (final head)

Per the compute rule, I re-verified the delta from `8612d3d`: a diff-read of the v2.6.9 merge, full QA of the code change, and a diff-read of the docs. New scratch copies used ports 9781–9789, PG databases `qa2_78r_*` and temp SQLite `DATA_DIR`s. Logs are `r-*` in `/workspace/qa2-logs/pr78/`.

### 17.1 Head, CI and commits

- `fa145765696b0198e2435d21f74f98cf38e2034c` was confirmed as the head through the API (mergeable `clean`). Run 40101120543 (`pull_request`) was 10/10 `success`. The head then moved by one commit, to **`21f84323eff716f3d613c6ea0536dfba448c7d7b`**. Compare `fa14576...21f8432`: 1 ahead, 0 behind, parent `fa14576`.
- Run 40102018974 at `21f8432` (`pull_request`, started 17:20 Athens time, UTC+3): **10/10 `success`** (17:20–17:30 Athens time, UTC+3): Lint, Headless mobile checks, Docker build + smoke test, Visual E2E 1/3–3/3, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Conventional Commits and Secret scan. The PR is `clean`.
- Subjects:
  - `chore: merge main v2.6.9`;
  - `refactor(security): refuse secret saves with an unusable key`;
  - `test(security): cover unusable-key and moved-address cases`;
  - `docs: correct key file permissions`;
  - `docs: use numeric gid 82 for key file permissions`.
  All pass `scripts/check-commits.sh --message`, and the Conventional Commits check is green. `refactor` describes a behaviour change, which I note as I-10.

**PASS**

### 17.2 v2.6.9 merge `8467fb3` (diff-read)

- I rebuilt the merge of `8612d3d` and `3f729a3` (base `d821bb8`) locally. **Only `CHANGELOG.md` conflicts.** Every other file in `8467fb3` is byte-identical to the clean auto-merge, including main's #80 (`session_idle_hours`, migration 0027) and its QA docs. No #78 code was touched.
- The resolution adds nothing beyond main's v2.6.9. `[2.6.9]` to EOF is **byte-identical** to `main` `3f729a3` (the v2.6.9 tag commit) at `8467fb3`, `fa14576` and `21f8432`. #78's bullet stays under `[Unreleased]`, linked to #78, and now says a configured but unusable key refuses secret saves.

**PASS**

### 17.3 M-1: an unusable key fails closed (`e62bfce`)

`secret_key_state()` now returns the key or a cause: `malformed`, `malformed_file`, `unreadable`, `inside_data` or `sodium`. `secret_seal()` throws a `UserError` naming the cause ("… The secret was not saved.") whenever there's a cause. The token route answers 422. The section save collects the error under `autofill_token` and stores nothing from the form. The panel warning and a once-per-process log line name the cause.

**Live HTTP probe** (`php -S`, sign-in on, autofill stub; **52/52 on PHP 8.4 and 52/52 on PHP 8.3**). Each cause was tested with a stored envelope and token row in place:

- malformed `APP_KEY`;
- 31-byte `APP_KEY`;
- root-owned 0400 `APP_KEY_FILE` that `www-data` can't read;
- file inside `DATA_DIR`;
- symlink into `DATA_DIR`;
- file under `originals/`;
- non-32-byte file;
- the sodium test switch with `APP_KEY`, and with `APP_KEY_FILE`.

For each one:

- **Panel:** the warning names the cause and no longer says "Set APP_KEY".
- **Token form:** **422** with the cause and "The secret was not saved.". The stored row is byte-identical.
- **Section save with a new token:** **422**. Neither the token row nor the address changes.
- **Leaks:** no key or token text in any response or the server log. The log line names the cause only.

Further checks:

- **No key:** the warning shows, the save answers 303, and the value is stored plaintext. **No key with sodium missing:** the same.
- **Valid key:** the save stores `enc:v1:`.
- **Real sodium missing** (the image rootfs through my own overlay, with the sodium ini removed; overlay removed afterwards, rootfs unchanged): with `APP_KEY` set, the save is refused ("The sodium extension is missing…") and the row is unchanged. With no key, it stores warned plaintext.

**Every writer** (I grepped every `settings_store`, `settings_set` and `secret_persist` call): a non-empty token can be written only by the token route and the section save, and both go through `secret_seal()`.

- `settings_reset_section` excludes secret keys. `settings_seed` writes `''`.
- Import and restore ignore secret and internal keys (CLI and HTTP). CLI `set` refuses the token.
- A direct `settings_store()` with a cause throws, and `settings_save()` returns the error and writes nothing (CLI probe, 8.3 and 8.4).
- The write-on-read upgrade with a malformed key leaves a legacy row alone: no reseal, no exception.
- `key rotate` with the sodium switch exits 1 with nothing changed and no key printed, but says "The new key must be base64 of 32 bytes." (N-8). With a malformed `APP_KEY` and a legacy row, it encrypts with the new key (`rotated 0, encrypted 1`), which is the intended upgrade.

**Rotation re-run** (8.3/8.4 × SQLite/PG17): 64 checks pass. The SQLite leg shows the same harness artifact as in §8 (my own read-only WAL).

**`APOTHEKE_SODIUM` test switch** (Coder's stated risk):

- It's read with `getenv()` only, and only the exact value `'0'` matters. Under FPM, request headers arrive `HTTP_`-prefixed, and `php -S` doesn't map them into the environment at all.
- Setting `APOTHEKE_SODIUM=0` in the query string, the form body, a request header or a cookie had **no effect** on a valid-key save (still `enc:v1:`).
- If an operator sets it, it can only move toward "refuse" (key set) or "warned plaintext" (no key, the same as without the switch). It can't make a key-set save store plaintext. Graded I-9.

**PASS (M-1 resolved)**

### 17.4 L-1: token-follows-address (`e62bfce`)

`autofill_drop_token_if_moved()` now asks `secret_setting_nonempty()`, which reads the stored row rather than the decrypted value. Over HTTP, with a wrong key, a missing key or a malformed key, an address change cleared the stored row ("saved-token-cleared"). The old envelope can't come back for the new address.

**PASS (L-1 resolved)**

### 17.5 Suites at `fa14576`

| Leg | Result |
|---|---|
| PHP 8.3, SQLite | OK, 459 tests, 3440 assertions, 13 skipped |
| PHP 8.3, PG17 (`qa2_78r_83`) | OK, 459 tests, 3468 assertions, 3 skipped |
| PHP 8.4, SQLite | OK, 459 tests, 3440 assertions, 13 skipped |
| PHP 8.4, PG17 (`qa2_78r_84`) | OK, 459 tests, 3468 assertions, 3 skipped |

Each leg also passed the CLI migrate, idempotent re-run and seed (`CLI_OK`). The skips are environmental, as in §3.

**PASS**

### 17.6 Mutations on the `7cda2ae` tests (17, PHP 8.4 SQLite)

Filter: `SecretAtRestTest|SettingsTest|BackupTest`, then `HttpSuiteTest` for anything that survived. The file was restored after each run, a single runner was used, and the baseline was green (59 tests). **16 of 17 killed.**

| # | Mutation | Result |
|---|---|---|
| R01 (mine) | `secret_seal` warns and returns plaintext instead of refusing | killed (unit) |
| R02 | malformed `APP_KEY` treated as no key | killed (unit) |
| R03 | unreadable `APP_KEY_FILE` treated as no key | killed (unit) |
| R04 | `APP_KEY_FILE` inside `DATA_DIR` treated as no key | killed (unit) |
| R05 | non-32-byte `APP_KEY_FILE` treated as no key | killed (unit) |
| R06 | sodium check dropped on the `APP_KEY` branch | killed (unit) |
| R07 | sodium check dropped on the `APP_KEY_FILE` branch | **survived** (unit and HTTP), N-7 |
| R08 (mine) | token route refusal answers 200 instead of 422 | killed (HTTP suite) |
| R09 | section save swallows the refusal | killed (unit) |
| R10 | section save doesn't catch the refusal (500) | killed (unit) |
| R11 | L-1 guard back to the decrypted value | killed (unit) |
| R12 | stored-row check always false | killed (unit) |
| R13 | panel shows the generic "Set APP_KEY" for an unusable key | killed (unit) |
| R14 | cause log line never written | killed (unit) |
| R15 | refusal message doesn't name the cause | killed (unit) |
| R16 (mine) | no key with sodium missing refuses instead of warning | killed (unit) |
| R17 (mine) | `APOTHEKE_SODIUM` switch ignored | killed (unit) |

- R07: with sodium missing and a valid `APP_KEY_FILE`, the mutant falls through to `secret_seal()`'s no-sodium branch and would store warned plaintext, which is the M-1 behaviour, on the file path only.
- The real code refuses in that case, as my live probe confirmed for the switch with `APP_KEY_FILE`. The gap is a missing test, not a code defect (N-7).

**PASS with N-7**

### 17.7 Docs and Compose: `fa14576` and `21f8432`

- **`fa14576`:** `chmod 600` and `0600` are gone from the tree. The CHANGELOG bullet is linked. But the advice was `chown root:www-data` with `chmod 0440`, and it said a Compose `secrets:` file "needs no extra mode".
  - `chown` runs on the host. There, `www-data` is gid 33 on Debian and Ubuntu (this box too) or doesn't exist, not the image's gid 82.
  - Outside Swarm, Compose bind-mounts a `file:` secret with the host file's owner and mode and ignores `uid`, `gid` and `mode`.
  - So following the advice gives a key file `www-data` can't read inside the container. Since `e62bfce`, that fails closed with a named cause ("APP_KEY_FILE could not be read"), not plaintext. So it's a Low setup trap rather than a reopening of M-1. Graded **L-3** at `fa14576`.
- **`21f8432` (docs only, diff-read):**
  - It changes only comments in `docker-compose.yml`, plus `docs/configuration.md`, `docs/deployment-and-operations.md` and `docs/getting-started.md`. The compare shows no code, test, CI or CHANGELOG change.
  - The advice is now `chown root:82 secrets/app_key && chmod 0440 secrets/app_key` everywhere: Compose (both spots), configuration, deployment and getting-started. It explains that `www-data` is gid 33 or missing on the host.
  - It states that plain `docker compose` bind-mounts a `file:` secret with the host file's owner and mode and ignores `uid`, `gid` and `mode`, so the same `root:82` and `0440` apply. That matches Compose's documented non-Swarm behaviour.
  - A whole-tree grep finds no `root:www-data`, `chmod 600`, `0600` or "needs no extra mode".
  - The key file stays at `./secrets/app_key`, mounted read-only at `/run/secrets/app_key`, outside `./data` and every backup root. The "keep the key file outside ./data" comment remains.
  - For the shipped image, `root:82` with `0440` gives php-fpm's `www-data` (uid 82, gid 82) read access through the group and keeps the file non-writable. That's correct. **L-3 resolved.**
- **At `21f8432`:**
  - `scripts/lint.sh`: exit 0.
  - `check-links`: exit 0.
  - The CHANGELOG is byte-identical to `fa14576`, and `[2.6.9]` to EOF still matches `main`.
  - gitleaks on the 4 changed files: no leaks.
- **At `fa14576`:** gitleaks on the 21 text files changed since `8612d3d` found no leaks. `lint.sh` and `check-links` exited 0.

**PASS (L-3 resolved)**

### 17.8 Findings at the re-check head

| Id | Sev | Status | Finding | Recommendation |
|---|---|---|---|---|
| M-1 | Medium | **Resolved** (`e62bfce`, `7cda2ae`, `fa14576`, `21f8432`) | An unusable configured key failed open (§10). It now refuses with 422, a named cause, and no write on every writer. No key stays warned plaintext. | — |
| L-1 | Low | **Resolved** (`e62bfce`) | Token-follows-address with a wrong or missing key (§10). Presence now comes from the stored row, and it's tested. | — |
| L-3 | Low | **Resolved** (`21f8432`) | **Hardening (S-L5 / key file permissions):** at `fa14576`, the docs said `chown root:www-data`, which resolves the host's group (gid 33 or none), not the image's 82, and said Compose secrets need no extra mode. Fail-closed, but a setup trap. | — (`root:82`, `0440`, and the secret-file rule are now documented) |
| L-2 | Low | Open | Non-`enc:v1:` `enc:` prefixes are treated as plaintext and resealed (§10). | As §10. Follow-up. |
| N-1 to N-6 | Nit | Open | As §10. N-1: the key file's mode is still not checked; the docs now advise 0440. N-2: the `#[\SensitiveParameter]` gap is unchanged. | As §10. Follow-up. |
| N-7 | Nit | New | Test gap: no test covers sodium missing with an `APP_KEY_FILE` key. Mutation R07 removes that guard and the suite stays green, though the code refuses correctly (live probe). | Add the `APP_KEY_FILE` twin of the sodium-missing test. |
| N-8 | Nit | New | `key rotate` with sodium missing says "The new key must be base64 of 32 bytes." It exits 1 with nothing changed, but the message misleads (the §9 message family). | Name the missing sodium extension, as the panel does. |
| N-9 | Nit | New (Coder's stated risk) | When the stored token can't be decrypted (wrong key, tamper), the panel says "No token set." although a row is stored, and since `e62bfce` an address change treats it as present. This is the L-2 area. | Say a token is stored but can't be read with the current key. |
| I-1 | Info | Open | `APP_NEW_KEY` in the claim, `APP_KEY_NEW` in the code and docs. | — |
| I-2 to I-7 | Info | Unchanged | As §10. | — |
| I-8 | Info | New (Coder's stated risk) | The cause log line is written once per PHP process, so once per FPM worker, and again after each worker recycle. That's bounded noise and the line carries no secret. | — |
| I-9 | Info | New (Coder's stated risk) | `APOTHEKE_SODIUM=0` test switch: environment-only, can't be set from a request (§17.3), and can only move toward refusing or the existing no-key behaviour. | Optionally document it as test-only. |
| I-10 | Info | New | `e62bfce` uses `refactor(security):` for a behaviour change (refusing saves). It passes the commit check, and the handwritten CHANGELOG bullet covers it. | `fix(security):` next time. |

| Severity | Open | IDs |
|---|---|---|
| High | 0 | — |
| Medium | 0 | — (M-1 resolved) |
| Low | 1 | L-2 (L-1 and L-3 resolved) |
| Nit | 9 | N-1 to N-9 |
| Info | 10 | I-1 to I-10 |

### 17.9 Verdict

**PASS at `21f84323eff716f3d613c6ea0536dfba448c7d7b`** (code tested at `fa14576`; `21f8432` diff-read as docs only). 0 High, 0 Medium, 1 Low (L-2), 9 Nit, 10 Info.

- M-1, L-1 and L-3 are resolved.
- Every unusable-key cause refuses with a named cause and writes nothing, on PHP 8.3 and 8.4. No key stays warned plaintext. A valid key seals and rotates.
- The suites are green on 8.3 and 8.4 × SQLite and PG17 (459 tests each). 16 of 17 mutations were killed, and the survivor is the N-7 test gap.
- **Follow-ups (not blocking):** L-2, N-1 to N-9, I-1, I-9 (optional doc).
