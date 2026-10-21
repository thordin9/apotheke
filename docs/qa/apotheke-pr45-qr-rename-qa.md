# QA: apotheke PR #45, `refactor(qr): rename qr_php84_jit_is_tracing` (#36 N-2, N-4)

- **PR:** [#45](https://github.com/thordin9/apotheke/pull/45), `cursor/refactor-qr-jit-rename-9d44` into `main`.
  Draft, mergeable `clean`.
- **Head:** `baa0a73a5e7cff2f7052819db39cc2b24136f73e`, one commit on top of `main` (`c654569`, after #40).
- **Follow-up to:** N-2 (the helper name) and N-4 (the guard comments) in `docs/qa/apotheke-pr36-qr-jit-fix-qa.md`.
- **Date:** 2026-10-21, 10:19–10:30 EEST.
- **Scope:**
  - Static checks, PHPUnit, `tests/run.sh` and a local release replay.
  - No live services, no credentials, and nothing outside public GitHub data.
- **Verdict: PASS.** No high, medium or low findings, one nit and three info notes.

## Plan

1. **Delta.** Run `compare_commits main...baa0a73` and `diff -r` the tarballs. Expect exactly `AGENTS.md`, `src/qr.php` and
   `tests/Unit/QrCodeTest.php`.
2. **Names and comments only.**
   - Run `php -l` on both PHP files.
   - Compare the `token_get_all()` streams of `main` and the head, without comments and whitespace, after mapping
     `qr_php84_jit_is_tracing` to `qr_jit_is_tracing`.
   - Compare the diff with `539807b` (the same rename, made on #42) for the same three files.
3. **Leftovers.** Search the head tree for the old name and for `php84`.
4. **Comment accuracy** against #36's guard and the #36 measurements:
   - the guard has no version check;
   - `1235` gives a wrong matrix on 8.2 and 8.3;
   - `1235` hangs `GET /labels` on 8.4.
5. **Checks.**
   - Full PHPUnit.
   - The JIT tests and the guard under `opcache.jit=1235`.
   - `tests/run.sh`, which runs lint, the link checker, shellcheck and the integration tests.
   - gitleaks, and a scan of the added lines.
6. **Release replay** on a rebuilt history from v2.5.4 through #40, with real commit messages and dates:
   - #45 alone;
   - #45 then #44 (`10f4d80`), and #44 then #45.
7. **#44 and #45 overlap.** Check for a textual conflict in either merge order.
8. **CI** on the head, through the API.

Method:
- Codeload tarballs, each checked with `git get-tar-commit-id`:
  - head `baa0a73…`, `main` `c654569…`, #44 `10f4d80…`, `539807b…`, and `feature/ci4` `2d715bd…`.
- Host PHP 8.4.26 with `vendor/` from the same `composer.lock`. The replay uses `scripts/release.php` from the tree.

## Results

### 1. Delta: PASS

| Commit | Message | Files |
|---|---|---|
| `baa0a73` (parent `c654569`) | refactor(qr): rename qr_php84_jit_is_tracing | `AGENTS.md` +1/−1, `src/qr.php` +10/−9, `tests/Unit/QrCodeTest.php` +14/−14 |

The compare shows 1 commit ahead, 0 behind, 3 files and +25/−24. `diff -r main head` lists the same 3 files and nothing
else.

### 2. Names and comments only: PASS

- `php -l` reports no syntax errors in `src/qr.php` or `tests/Unit/QrCodeTest.php`.
- **Token streams:** comments and whitespace are dropped, and the old name is mapped to the new one.

| File | Tokens main / head | Identical after the rename | Changed code tokens before mapping | Changed comment tokens |
|---|---|---|---|---|
| `src/qr.php` | 175 / 175 | **yes** | 2 (definition and call) | 1 (the header block) |
| `tests/Unit/QrCodeTest.php` | 1416 / 1416 | **yes** | 12 (the 12 asserts) | 1 (one docblock) |

- `AGENTS.md` changes one line in the file map, the `src/qr.php` row.
- No string, `function_exists()` call or other reference to the helper name exists outside these files. See §3.

**Compared with `539807b` on #42:**
- The function rename is the same: the definition, the one call, and the 12 asserts.
- The diff context differs only where `main` differs from `feature/ci4`: the line numbers in `AGENTS.md`, and
  `parity/` in the `tests/` row. `src/qr.php` and `QrCodeTest.php` are byte-identical on `main` and `feature/ci4`.
- The comment wording differs in three places that are not caused by `main` (N-1):

| Where | `539807b` | #45 |
|---|---|---|
| `AGENTS.md` | "On every PHP version (affected: 8.2–8.4), turns function JIT off" | "On every PHP version, turns function JIT off" |
| `src/qr.php` header | "On PHP 8.2 and 8.3, opcache.jit=1235 … can emit a wrong matrix; on PHP 8.4 that mode can hang GET /labels. … The guard runs on every PHP version (affected: 8.2–8.4) …" | Same content, reworded and re-wrapped: "opcache.jit=1235 … can emit a wrong matrix on PHP 8.2 and 8.3, and can hang GET /labels on PHP 8.4. … The guard runs on every PHP version whenever function JIT is active (affected: 8.2–8.4)." |
| `QrCodeTest.php` docblock | Line 2 rewritten. Keeps "Every function-JIT mode is disabled before the encoder loads." | Line 2 rewritten. That sentence is replaced with "The guard runs on every PHP version." |

- `539807b` is not on any branch. The PM dropped it from the `main` → `feature/ci4` sync (#43), and #42 was closed
  without merging. So the wording difference can't cause a merge conflict.

### 3. Leftover old name: PASS

`rg qr_php84_jit_is_tracing` and `rg php84` in the head tree find only two lines, both in the historical QA report
`docs/qa/apotheke-pr36-qr-jit-fix-qa.md` (the N-2 row and §1). That report correctly stays unchanged. There are no
hits in `src/`, `tests/`, `scripts/`, `bin/`, `public/`, `migrations/`, `docker/`, other `docs/`, `README.md` or
`AGENTS.md`. `ci4/` doesn't exist on `main`.

### 4. Comment accuracy: PASS

- **No version check:** the guard closure in `src/qr.php` only checks `opcache_get_status()`, `jit.on`,
  `opcache.jit` and `jit.kind`. So "runs on every PHP version whenever function JIT is active" is correct.
- **The new statements match the #36 measurements** (§2 and §9 of that report):
  - `1235` gives a wrong matrix on 8.2.34 and 8.3.35.
  - `1235` hangs on 8.4.26.
  - Other function modes are wrong on 8.4 (240/240 for `1205` and `function`), which is "can emit a different
    matrix".
  - Tracing modes match the interpreter.
  - "Affected: 8.2–8.4" is correct.
- The test docblock now names `1235` and both effects, and says the guard has no version limit. Both are correct.
- **README, `docs/configuration.md` and `docs/getting-started.md`** are unchanged and already accurate (I-3).
  - They name 8.2, 8.3 and 8.4 as affected by function JIT.
  - They don't describe the guard as version-limited.

### 5. Checks: PASS

| Check | Result |
|---|---|
| PHPUnit (full, host PHP 8.4.26) | `OK, but some tests were skipped`: 283 tests, 2121 assertions, 8 skipped (pgsql and zbarimg, as on `main`) |
| JIT tests with `opcache.enable_cli=1 opcache.jit=1235` | `OK (2 tests, 64 assertions)` |
| Guard under `1235` after loading `src/qr.php` | `opcache.jit` = `disable`, `jit.on` = false |
| `tests/run.sh` | PHP lint, JS syntax and shellcheck ok. **186 relative links in 16 files, 0 broken.** Integration **1423 passed, 0 failed**. |
| gitleaks | no leaks: diff, the 3 files, the head tree (`--no-git`), and the replayed commit (git mode) |
| Added lines | no URLs, hosts, IPs, e-mail addresses, tokens or account ids |

### 6. Release replay: PASS

The history was rebuilt from v2.5.4 with the real #40 commits, each with its real message and date. The #40 merge tree
equals `c654569`.

| Scenario | `scripts/release.php plan` |
|---|---|
| `main` now | `commits=4 bump= next= release=false` |
| #45 alone | `commits=5 bump= next= release=false` |
| #45, then #44 | `commits=6 bump=patch next=2.5.5 release=true` |
| #44, then #45 | `commits=6 next=2.5.5 release=true`, with a tree identical to the other order |

`apply` after #45 and #44 writes `VERSION` 2.5.5 and this section (dated on the day it is applied):

```markdown
## [2.5.5] - 2026-10-21

### Fixed

- **db:** skip CREATE SCHEMA when DB_SCHEMA already exists ([#44](https://github.com/thordin9/apotheke/pull/44))

### Changed

- **qr:** rename qr_php84_jit_is_tracing ([#45](https://github.com/thordin9/apotheke/pull/45))

### Maintenance

- **changelog:** docs: add the Imagick colour note to 2.5.4 ([#40](https://github.com/thordin9/apotheke/pull/40))
- docs: clarify that only the autofill JPEG is one channel ([#40](https://github.com/thordin9/apotheke/pull/40))
- **changelog:** docs: link #37 from the 2.5.4 images line ([#40](https://github.com/thordin9/apotheke/pull/40))
- **qa:** docs: add the v2.5.4 release QA report ([#40](https://github.com/thordin9/apotheke/pull/40))
```

- The `refactor` commit is listed under **Changed**, not under Maintenance, and it isn't omitted. It doesn't bump the
  version on its own.
- The generated line links only #45. The PR body's release note also links #36 (I-1).
- A `docs(qa):` commit added to #45 would add one more Maintenance line.

### 7. #44 and #45 overlap: PASS

- #44 changes 12 files: `README.md`, `config.example.php`, five `docs/` files, `src/config.php`, `src/db.php`,
  `src/views.php`, `tests/integration.php`, `tests/lib.php`, and the new `tests/Unit/PgsqlSchemaCreateTest.php`.
- It doesn't touch `AGENTS.md`, `src/qr.php` or `tests/Unit/QrCodeTest.php`, so the two PRs have no files in common.
- `git merge --no-ff` in both orders is clean, and both orders give the same tree.
- In the merged tree, #45's 3 files equal the #45 head, and #44's files equal the #44 head.

### 8. CI: PASS

CI run 39559611748 (`pull_request`) on `baa0a73` completed with **success**, 10:05:41–10:15:49 EEST.

| Check | Result |
|---|---|
| Lint | success |
| Conventional Commits | success |
| Secret scan | success |
| Tests (PHP 8.3, sqlite) | success |
| Tests (PHP 8.3, pgsql) | success |
| Docker build + smoke test | success |
| Headless mobile checks | success |
| Visual E2E (Playwright, shards 1/3, 2/3, 3/3) | success ×3 |

10/10 check runs succeeded.

## Findings

| ID | Sev | Finding | Suggestion |
|---|---|---|---|
| N-1 | Nit | The comment wording differs from `539807b` in three places not caused by `main`. `AGENTS.md` leaves out "(affected: 8.2–8.4)". The `qr.php` header is reworded with the same facts. The test docblock drops "Every function-JIT mode is disabled before the encoder loads." All of it is accurate, and `539807b` is on no branch, so it can't conflict. | Optional: restore the dropped docblock sentence, which describes what `testFunctionJitMatchesInterpreter` covers. |
| I-1 | Info | In the next release, the rename shows under **Changed** as "**qr:** rename qr_php84_jit_is_tracing (#45)". The PR body's suggested note also links #36. | If the #36 link is wanted, edit it in after the release, as was done for 2.5.4. |
| I-2 | Info | `feature/ci4` still uses the old name in `src/qr.php` and `tests/Unit/QrCodeTest.php`. Its copies are byte-identical to `main`'s, so the next `main` → `feature/ci4` sync brings the rename in cleanly. | Check the name at that sync. |
| I-3 | Info | README, `docs/configuration.md` and `docs/getting-started.md` still say "PHP 8.2, 8.3 and 8.4" for function JIT in general. That is correct (8.4 miscompiles under the other function modes), and they don't describe the guard as version-limited. | None. |

No high, medium or low findings.

## Verdict

**PASS.**

- #45 changes exactly `AGENTS.md`, `src/qr.php` and `tests/Unit/QrCodeTest.php`.
- After mapping the rename, the PHP token streams are identical: only names and comments change.
- The old name is gone outside the historical QA report.
- The comments match #36's guard and measurements.
- PHPUnit, `tests/run.sh`, the link checker, gitleaks and CI (10/10) all pass.
- Merging #45 alone doesn't release. Together with #44, in either order and with no conflict, it gives 2.5.5 with the
  `fix(db)` line, and the refactor listed under Changed.
