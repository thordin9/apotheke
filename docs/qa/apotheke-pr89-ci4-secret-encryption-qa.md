# QA: apotheke PR #89, CI4 stored-secret encryption at rest (S-L5)

- **Head:** `8d911a6b5a2604522f8bd19385838c56961a474f` on `feature/ci4` `c3340fe` (4 commits: `e33515a` legacy hunks, `3b203e5` CI4 code and tests, `8691137` docs, `8d911a6` test keys and `.gitleaks.toml`).
- **Gate:** full (security fix). CI run 40124034825 (22/22) is the author's report; the GitHub API was not reachable from the QA box, so CI wasn't re-read.
- **Verdict: PASS at `8d911a6`** with 0 High, 0 Medium, 0 Low and 4 Nit findings.

## Checklist

| # | Check | Result |
|---|---|---|
| 1 | `.gitleaks.toml` entry is narrow | **Pass.** `condition = "AND"`. The path regex matches exactly `ci4/tests/Core/SecretBoxTest.php` and `ci4/tests/Settings/SettingsServiceTest.php`. The value regex `^…LW[EI]t…$` matches exactly two strings: tried with all 64 base64 characters, only `E` and `I` match. Both values base64-decode to the 32-byte placeholders `placeholder-key-a-32-bytes-long!` and `placeholder-key-b-32-bytes-long!`. gitleaks 8.30.1 (same pinned build and checksum as CI) on the history up to the head: with the PR config, 520 commits and no leaks; with the base config, 3 `generic-api-key` hits, all in `3b203e5`. Probe commit: the same value in another file, a different key-shaped value in an allowlisted file, and a look-alike file name are all still flagged. |
| 2 | Legacy hunks match main #78 (`7b550f5` against `32b1ef8`) | **Pass.** Blobs are identical for `src/secretbox.php`, `public/index.php`, `src/autofill.php` and `src/backup.php`. The changed lines are identical for `src/settings.php`, `bin/settings.php`, `Dockerfile`, `composer.json`, `tests/integration.php` and `tests/phpunit-bootstrap.php`. The `ci.yml` and `tests/lib.php` hunks add the same tokens to lines whose context differs on feature/ci4. The `composer.lock` hunk differs (see N-2). |
| 3 | AEAD | **Pass.** XSalsa20-Poly1305 `secretbox` with a 32-byte key (exact length, strict base64, optional `base64:` prefix). The nonce is `random_bytes(24)` on every seal: 2,000 seals gave 2,000 distinct nonces. Envelope length is 24 + 16 + plaintext. Flipping any one of the 57 bytes is rejected. An empty, invalid, short or extended body, a wrong key or no key all give `null`, which reads as unset. Nothing throws. |
| 4 | Database compatibility with main | **Pass.** The envelope is byte-compatible both ways: a legacy (main-identical) seal opens in CI4, and a CI4 seal opens in legacy. The CLI parity test runs both apps on one row for the usable, wrong, missing, malformed, no-sodium and in-data-dir key cases. A plaintext row is sealed on read by both apps. |
| 5 | M-1 refusal and the L-1 rule | **Pass.** A malformed `APP_KEY`, missing sodium, or an unreadable or refused `APP_KEY_FILE` gives a 422 with a named cause. The row is kept, and `save()` reports the error under `autofill_token`. With no key, the value stays plaintext and the notice is logged once. Token presence comes from the stored row, so an address change still clears a token the current key can't open. |
| 6 | Leakage | **Pass.** No key or token appears in logs, the 422 body, `export()` or `list` (checked in tests and in my own probe). `import()` skips `secret` and `internal` keys. Rotation stays in `bin/settings.php`, and `apotheke:settings key` sends you there. |
| 7 | Worker and Cloudflare phase | **Pass.** `SecretBox` and `SecretKey` have no static properties (checked by reflection). The key is resolved lazily for each instance, and `Services::settings()` builds the instance per request from `getenv()`. Storage stays behind `SettingsRepository`. |
| 8 | Suites | **Pass.** CI4 on PHP 8.4 with SQLite: 561 tests OK. CI4 on PHP 8.4 with PostgreSQL 17: 561 OK, and the pgsql proof passed. Legacy on PHP 8.3 with SQLite: 561 OK (CI4 needs PHP 8.4, so the 8.3 legs run legacy only). Running the CI4 module suite directly on pgsql with the `SecretBox\|SettingsService` filter: 46 tests OK. |
| 9 | Mutations (19, CI4 code, SQLite) | **15 killed:** nonce reuse, auth failure returning the envelope, the 422 dropped, never sealing, failing open with no key, malformed `APP_KEY` treated as none, a key file in the data directory allowed, the sodium check skipped for `APP_KEY`, the L-1 rule reverted, no plaintext upgrade, `save()` letting the 422 escape, `store()` not sealing, empty backup roots, log-once removed, and the key logged. **4 survived:** a short body accepted (equivalent, because sodium still refuses it) and the 3 gaps in N-1. |
| 10 | Docs and CHANGELOG | **Pass.** The new bullet is under `[Unreleased]` › Fixed. From the first released section to the end, the file is byte-identical to the base. `docs/HANDOFF.md` records S-L5. The tests use placeholder keys only. |

## Findings

| ID | Grade | Finding | Suggestion |
|---|---|---|---|
| N-1 | Nit | Test gaps (mutation survivors): a key decoding to 16–31 bytes; sodium missing with an `APP_KEY_FILE` key (the CI4 twin of main N-7); `password_hash` excluded from at-rest sealing. In each case the code behaves correctly. | Add the three unit cases. |
| N-2 | Nit | The `composer.lock` hunk was regenerated by a newer Composer (`stability-flags`/`platform-dev` `{}`, `plugin-api-version` 2.9.0), so it isn't byte-identical to #78. It has the same effect. | Expect this file in the #85 merge. |
| N-3 | Nit | `SettingsService::backupRoots()` re-implements the `originals_dir` resolution (`dirname(__DIR__, 3)`) instead of using `ApothekeConfig::originalsDir()`/`repoRoot()`. The result is the same today. | Reuse the config method. |
| N-4 | Nit | Main's #78 legacy tests (`tests/Unit/SecretAtRestTest.php`, the `integration_autofill.php` hunk) and the operator docs (`configuration.md`, `.env.example`, the Compose secret) aren't on feature/ci4 until #85. The PR body mentions only the Control Panel warning. | Mention this in the hand-off, or confirm that #85 brings them. |

Still open on main and inherited unchanged by design (parity): main L-2 (other `enc:` prefixes are read as plaintext) and main N-2 (no `#[\SensitiveParameter]`). The image keeps `zend.exception_ignore_args = On`.

## Security

Hardening for S-L5 on CI4. There are no High, Medium or Low findings. Encryption fails closed: an unusable configured key refuses the save with a 422 and writes nothing, and a value that can't be opened reads as unset. Nonces are fresh on every seal, and nothing is logged or echoed. The allowlist is limited to two exact placeholder values in two test files, so it doesn't widen the secret scan.

## Verdict

**PASS at `8d911a6b5a2604522f8bd19385838c56961a474f`.** The N findings are follow-ups. The `docs(qa)` commit that adds this file should change nothing else.
