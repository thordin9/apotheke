# QA: apotheke PR #43, `main` → `feature/ci4` sync (2.5.3 and 2.5.4)

> **Status: FINAL.** Verdict: **PASS.** The merge commit `2dab8e8` passes every check, and it is the PR head. M-1 (the
> `revert:` head on #42) is closed: #42 was closed without merging, and the sync continues as #43 from a new branch that
> points at `2dab8e8`. Nothing was force-pushed or rewritten.

- **PR:** [#43](https://github.com/thordin9/apotheke/pull/43) (draft, into `feature/ci4`). It replaces
  [#42](https://github.com/thordin9/apotheke/pull/42), which was closed unmerged as a record.
- **Reviewed head:** merge commit `2dab8e82ce6a6f1575e791a3e41cd5c2609a8032`, with parents `4b27ab0` (`feature/ci4`
  after #38) and `c654569` (`main` after #40, v2.5.4). Its tree is `13c7e9a0eb83f8f3a765f7111373208b2a508ad9`.
- **Scope change (PM):** `539807b` (`refactor(qr): rename JIT tracing helper (N-2, N-4)`) is not part of the sync. The PM
  deferred the rename to cutover. The rename-diff check was therefore dropped.
- **Merge base:** `42f497e` (from the GitHub compare API). `main` is 24 commits ahead of it and `feature/ci4` is 44 ahead.
- **CI:**
  - Run 39484807235 on `2dab8e8` (the #43 head), 22:35 to 22:45 Europe/Athens: 22/22.
  - Earlier on #42: run 39481259969 on `539807b` was 22/22, and run 39483149351 on `19264c0` was 21/22 (see M-1).
- **Date:** 2026-10-20 and 2026-10-21, Europe/Athens. No live services. PostgreSQL 17 was a throwaway local container,
  and `php8.4-pgsql` was installed for the run and removed afterwards.
## Test plan

1. **Redo the merge.** Build a local repository from tarballs (each checked with `git get-tar-commit-id`) with
   `42f497e` → `4b27ab0` and `42f497e` → `c654569`. Merge them, list the conflicts, and compare every file with
   `2dab8e8`. For each conflicted file, check that both sides' intent is kept: no CI4 lines lost, and nothing lost from
   `main` (the #36 QR JIT fix, #37's `image-color.php` with Imagick flatten and CMYK, and the #39/#40 changelog and docs).
   Verify the PM's list of 8 conflicted files.
2. **QR identity.** At the head, `src/qr.php` and `tests/Unit/QrCodeTest.php` must be byte- and blob-identical to `main`
   `c654569`.
3. **Next sync.** Simulate `main` → `feature/ci4` after #42, with and without the rename on `feature/ci4`, against
   several hypothetical `main` changes. Verify Coder's claim: the merge base becomes `c654569`, and it only conflicts if
   `main` edits the same `qr.php`/`QrCodeTest.php` hunks. Then replay `release.php plan` on `main` with a `refactor(qr):` PR
   that touches the same 3 files.
4. **#37 photo requirement.** Over HTTP on the legacy app, with GD and with Imagick forced:
   - the stored original keeps the sha256 of the upload;
   - the autofill stub only gets a reduced JPEG (at most 1024 px, no metadata);
   - `image-color.php` is wired in.

   For CI4, check whether it applies at all.
5. **Suites.** PHPUnit for legacy and ci4, each on SQLite and real PostgreSQL 17, plus `tests/run.sh`. Then the link
   checker, shellcheck, actionlint and gitleaks on the delta. Confirm the Dockerfile and workflows are unchanged versus
   `4b27ab0` and `main`.
6. **Changelog and release.** `[2.5.3]` and `[2.5.4]` must be byte-identical to `main`, and the Unreleased section must
   be `feature/ci4`'s.
7. **API state.** Confirm the PR head via `get_pull_request` and the CI state on that head.

## Results

### 1. Merge replay (PASS)

My merge of `4b27ab0` + `c654569` (base `42f497e`) conflicts in exactly 8 files:

> `AGENTS.md`, `CHANGELOG.md`, `README.md`, `docs/architecture.md`, `docs/ci.md`, `docs/getting-started.md`, `src/qr.php`,
> `tests/Unit/QrCodeTest.php`

This matches the PR description. The PM's list differs in two places. `composer.json` and `docs/configuration.md` changed
on both sides, but in separate hunks, so git merged them cleanly. They are not conflicts, and both match `2dab8e8`.
Main's `ext-imagick` suggest text is present in `composer.json`. `src/qr.php` and `tests/Unit/QrCodeTest.php` are the
real conflicts the PM's list leaves out.

- **Cleanly merged files:** all 938 are byte-identical to `2dab8e8`.
- **File set:** identical. Nothing is missing on either side.
- **Tree:** a commit built from the tarball tree has tree id `13c7e9a0eb83f8f3a765f7111373208b2a508ad9`, the same as
  GitHub's tree for `2dab8e8`.
- **Main's code:** `src/`, `public/` and `migrations/` are identical to `main`.

**Lost-line check.** For every line either side added since the merge base, I checked whether it is still in the head.
Nothing from `main` is lost in any of the 8 files.

The CI4 lines that are gone are all the old "PHP 8.4 only" QR wording:
- `AGENTS.md`, `README.md` and `docs/architecture.md`: one line each.
- `src/qr.php`: the comment and the `PHP_VERSION_ID < 80400` early return.
- `QrCodeTest.php`: the 8.4 note and the 8.4 skip.

`main`'s #36 fix supersedes all of these. The other two missing lines are rewraps, not losses (see the table).

| File | Resolution | Both intents kept? |
|---|---|---|
| `src/qr.php` | `main` | Yes. `feature/ci4` had its own earlier 8.4-only guard (from CI4 PR 3). `main`'s #36 version is a superset: the guard runs whenever function JIT is active, with the same helper. No CI4-only function is lost. |
| `tests/Unit/QrCodeTest.php` | `main` | Yes. The same test methods are on both sides. `main` adds the `0205` case and drops the 8.4-only skip. |
| `AGENTS.md` | `main`'s QR line | Yes. All CI4 sections stay (the CodeIgniter 4 rule, the 3.0 constraints, the module rules, `tests/parity/`). |
| `README.md` | `main`'s QR line | Yes. The CI4 requirements, CI table and release note stay. `main`'s JIT and Imagick paragraph and the 2.5.4 coverage table are in. |
| `docs/architecture.md` | `main`'s QR line | Yes. The CI4 skeleton paragraph stays. `main`'s `image-color.php` line and the "white and sRGB" note are in. |
| `docs/getting-started.md` | both | Yes. The CI4 lines for the shipped 2.5.x image and `--build-arg PHP_VERSION=8.4` stay, joined with `main`'s Opcache, no-JIT and Imagick flatten/CMYK/grayscale sentences. `main`'s non-Docker JIT note and the CI4 dev-server line are both present. |
| `docs/ci.md` | CI4 row plus `main`'s clause | Yes. The two-cell CI4 Docker row stays, with `main`'s "an Imagick fallback flattens transparency onto white and writes sRGB instead of CMYK" inside `docker-heic.sh`. |
| `CHANGELOG.md` | CI4 Unreleased, then `main`'s releases | Yes. See §6. |

### 2. QR byte identity at the head (PASS)

| File | head = `main` bytes | blob (head = `main`) | `feature/ci4` blob |
|---|---|---|---|
| `src/qr.php` | yes | `7569a04d24d130d94a167ebd0f1dbb5a430af420` | `30b0311…` |
| `tests/Unit/QrCodeTest.php` | yes | `5aa7435cadbfcba29c268d52b5302a9e299ad60c` | `63d59e6…` |

The same blobs are on `main` at `cd81290` and `b954a5e` (#36), so the head carries the released 2.5.3 guard unchanged.

### 3. Next sync and the deferred rename (INFO)

The rename isn't in this sync, so the old name `qr_php84_jit_is_tracing` stays at the head, exactly as on `main`. It
appears in `src/qr.php`, `QrCodeTest.php` and historical QA docs. The PM can decide between a matching `main` PR and
waiting for cutover.

**Simulation.** I built a commit with `2dab8e8`'s tree and parents `4b27ab0` and `c654569`, as a real merge of #42 would
produce. Its merge base with `main` is `c654569`, which confirms Coder's claim: the next sync starts from `main`'s tip.
I then merged it with several hypothetical `main` changes:

| Hypothetical `main` change after #42 | `feature/ci4` = #42 (no rename) | `feature/ci4` = #42 + `539807b` rename |
|---|---|---|
| `refactor(qr):` with the same rename (3 files) | clean, result equals `main` | clean (both sides made the same change) |
| edit in `qr.php` away from the renamed hunks | clean | clean |
| edit to the guard comment that `539807b` reworded | clean | **conflict** in `src/qr.php` |
| new assert next to the renamed calls in `QrCodeTest.php` | clean | **conflict** in `tests/Unit/QrCodeTest.php` |
| unrelated doc change | clean | clean |

**Coder's claim holds.** With the rename only on `feature/ci4`, a future sync conflicts only when `main` touches the same
`qr.php`/`QrCodeTest.php` hunks. With the rename dropped, as now, `feature/ci4`'s two QR files equal the merge base, so
no `main` change can make them conflict. A later `main` `refactor(qr):` PR then flows in cleanly.

ITAdvisor's concern is therefore resolved by the scope change. Grade: **Info (process)**.

**Release replay.** I ran `scripts/release.php plan` on `main` `c654569`, tagged `v2.5.4`, after a merged PR that touches
the same 3 files (`AGENTS.md`, `src/qr.php`, `tests/Unit/QrCodeTest.php`):

| Commit subject | `plan` output |
|---|---|
| `refactor(qr): rename JIT tracing helper (N-2, N-4)` | `commits=1`, `bump=` (empty), `release=false` |
| `fix(qr): …` (control) | `bump=patch`, `next=2.5.5`, `release=true` |

A `refactor(qr):` PR on `main` doesn't release, as expected.

### 4. #37 photo requirement (PASS on legacy; CI4 not applicable)

**Legacy app, at the head, PHP 8.4, SQLite.** I used the #37 QA fixtures: transparent PNG/GIF, CMYK with and without an
ICC profile, a 3000×2000 CMYK image, grayscale, EXIF orientation 6, and HEIC with GPS or alpha. For each fixture, the
photo was analysed twice: through the stored photo and through a direct upload.

| Check | GD (default) | Imagick forced (`imagecreatefromstring` disabled) |
|---|---|---|
| Original sha256 equals the upload, before and after Analyze | 13 of 13 | 11 of 11 (HEIC cases need GD off) |
| Stub receives a JPEG, never the upload or the original | yes, every call | yes, every call |
| Longest side at most 1024 px | yes (3000×2000 → 1024×683, orientation 6 → 512×1024) | yes |
| Metadata in the sent JPEG | none: no APPn (EXIF/ICC/XMP) segment. Only GD's own `COM` creator marker. | none (no APPn, no `COM`) |
| Transparent areas | white | white |
| CMYK | sRGB, not inverted (for example, 221,96,64 top-left on both engines) | same |
| Temp files left | 0 | 0 |

The sent analysis JPEGs are byte-identical to the #37 QA run on PHP 8.4: Imagick 10 of 10, GD 11 of 11, all part of
that run's 12. `src/image-color.php` is required by `src/items.php` and `src/autofill.php`. It provides
`imagick_match_gd()`, `imagick_cmyk_to_srgb()`, `imagick_has_transparency()` and `imagick_srgb_icc()`.
`ImagickColorTest` (4 tests) and the fixtures `tests/fixtures/cmyk.jpg` and `tests/fixtures/transparent.png` came from
`main`.

**CI4.** The requirement doesn't apply yet. CI4 routes only `/healthz` and `/health`, and it has no upload or autofill
code.

### 5. Suites and scans (PASS)

| Suite (PHP 8.4, head tree) | Result |
|---|---|
| PHPUnit legacy@sqlite | OK, 337 tests, 9 skipped |
| PHPUnit legacy@pgsql (PG17) | OK, 337 tests, 6 skipped |
| PHPUnit ci4@sqlite | OK, 337 tests, 10 skipped |
| PHPUnit ci4@pgsql (PG17) | OK, 337 tests, 7 skipped |
| `ImagickColorTest` + `QrCodeTest` | OK, 17 tests, 6 skipped |
| `tests/run.sh` SQLite | 1423 passed, 0 failed |
| `tests/run.sh` PostgreSQL | 1179 passed, 0 failed |
| `ci4-pgsql-proof.php` after the pgsql cells | `rows=26` |

Local skips are environment-only. On the first local run of the sqlite cells,
`testShippedBootDoesNotWriteThePgsqlProbe` failed with "connection refused" (N-3). The sqlite cells passed once
`DB_HOST`/`DB_PORT` in the PHPUnit environment pointed at the local PostgreSQL, matching CI, where the service listens on
the default address. The test came in with #38 and is unchanged by this merge.

**Scans.**
- Links: 229 checked in 40 files, 0 broken.
- shellcheck (`scripts/*.sh`, `docker/entrypoint.sh`): clean. actionlint: clean.
- gitleaks:
  - The merge delta (the `main` side plus the merge, replayed) has no leaks.
  - A directory scan of the head tree has no leaks either.
  - `main`'s individual commits were scanned in their own QA rounds.

**Docker and workflows.**
- `Dockerfile`, `.dockerignore` and `.github/` are byte-identical to `4b27ab0`.
- `main` didn't change `Dockerfile`, `ci.yml` or `release.yml` since the merge base. The head's differences from `main`
  are `feature/ci4`'s own (the `PHP_VERSION` build arg and the CI4 matrix).
- `docker/php.ini` comes from `main` (it adds the no-JIT comment).

### 6. Changelog and release (PASS)

- From `## [2.5.4]` to the end of the file, `CHANGELOG.md` is byte-identical to `main` (17960 bytes, including the link
  references).
- `[2.5.4]` and `[2.5.3]` each match `main`.
- The `[Unreleased]` block is byte-identical to `feature/ci4`'s, and the link reference is now `compare/v2.5.4...HEAD`.
- `VERSION` is `2.5.4`, the same as `main`.
- `release.yml` only listens to `main`, so this sync can't release.

### 7. GitHub state (PASS, M-1 closed)

On #42, at 22:35 Europe/Athens on 2026-10-20:

- `get_pull_request` gave head `19264c09f367d5e797d892c56205dfeed7d3aa2a` (`revert: drop N-2/N-4 rename from the sync`,
  on top of `539807b`). Its tree is identical to `2dab8e8`.
- CI run 39483149351 on `19264c0` was 21/22. **Conventional Commits failed**, because `check-commits.sh` allows only
  `feat fix docs style refactor perf test build ci chore` and skips subjects starting `Revert `.

On #43, at 09:36 Europe/Athens on 2026-10-21:

- `get_pull_request` gives head **`2dab8e82ce6a6f1575e791a3e41cd5c2609a8032`** on branch `cursor/ci4-sync-main-2dab`,
  base `feature/ci4` at `4b27ab0`, draft, open.
- **CI run 39484807235 on `2dab8e8`**: all 22 jobs completed with `success`, from 22:35:52 to 22:45:12 Europe/Athens.
  The Conventional Commits job passes because the head is the merge commit, with no `revert:` subject.

## Findings

| ID | Sev | Finding | Fix |
|---|---|---|---|
| M-1 | Medium, process, **closed** | On #42, the PR head was `19264c0` (rename `539807b` plus a revert), not `2dab8e8` as the PM decided. The revert subject `revert: …` fails the Conventional Commits job (CI 39483149351, 21/22). The content is the same tree as `2dab8e8`, so nothing else changes. If the branch were merged as it is, `feature/ci4` history would also carry the rename and its revert. | Closed: #42 was closed, and #43 was opened from a new branch at `2dab8e8`, without a force-push. #43's head is `2dab8e8` and CI 39484807235 is 22/22 (§7). |
| N-1 | Nit (docs, follow-up) | `docs/ci.md` (the Tests paragraph after the job table) still says "On PHP 8.4, when opcache function JIT is on, `src/qr.php` sets `opcache.jit=disable`…". With `main`'s guard, this applies to every version whenever function JIT is active. The paragraph was outside the conflict. | Per the PM: reword it in CI4 PR 5's docs. |
| N-2 | Nit (changelog, follow-up) | The Unreleased Maintenance list keeps `fix: QR labels finish under PHP 8.4 function JIT…`, but `main` already released that fix as 2.5.3. At the 3.0.0 cutover it would be listed twice. | Drop it or merge it into the 3.0.0 notes at cutover. |
| N-3 | Nit (test, from #38, follow-up) | On the sqlite legs, the PostgreSQL half of `Ci4HttpTest::testShippedBootDoesNotWriteThePgsqlProbe` starts CI4 with `DB_DRIVER=pgsql` but no `DB_HOST`/`DB_PORT`. It reaches PostgreSQL only at the default address, ignoring `TEST_PG_HOST`/`TEST_PG_PORT`. It passes in CI (the service is on the default port) and fails locally otherwise. | Pass `TEST_PG_HOST`/`TEST_PG_PORT` into that server's environment. |
| I-1 | Info (process) | The rename (N-2/N-4 of #36) is deferred to cutover (PM decision). The old helper name stays on both branches for now, consistently. A `refactor(qr):` PR on `main` doesn't release (§3), and it syncs into `feature/ci4` without conflicts. | None: the PM deferred it to cutover. |
| I-2 | Info | The PM's conflict list named `composer.json` and `docs/configuration.md`. Both changed on both sides but merged cleanly. The other two real conflicts are `src/qr.php` and `tests/Unit/QrCodeTest.php`, which matches the PR description. | None. |
| I-3 | Info | Before the merge, `feature/ci4` had its own 8.4-only QR guard (CI4 PR 3). Taking `main`'s #36 version widens the guard to 8.2 and 8.3 on `feature/ci4`. That is intended, and the version is already released on `main` as 2.5.3. | None. |

There are no high or open medium findings. M-1 is closed. The N items are follow-ups.

## Verdict

**PASS. #43 at `2dab8e8` is clear to merge with `--merge` after the usual `docs(qa):` flow.**

- All 8 conflict resolutions keep both sides' intent. 938 cleanly merged files, the file set and the tree id all match
  my replay.
- `qr.php` and `QrCodeTest.php` are byte- and blob-identical to `main`.
- #37's photo behaviour holds on legacy. CI4 has no upload routes yet.
- Suites pass on legacy and ci4, SQLite and PostgreSQL 17, and `tests/run.sh` passes on both databases.
- Links, shellcheck, actionlint and gitleaks are clean.
- Dockerfile and workflows are unchanged.
- The changelog's released sections match `main`.
- Next sync: with the rename dropped, nothing can conflict in the QR files. A `refactor(qr):` PR on `main` doesn't release.
- M-1 is closed: #43's head is `2dab8e8` and CI 39484807235 is 22/22.

**Evidence** (QA box, `/workspace/apotheke-qa/pr42/`):

- Inputs: `tars/` (with `logs/tarballs.txt`), `t/<sha>/`, `repo/` (the replay: `mb`, `ci4tip`, `maintip`, `S` and the
  simulation branches), `pr.json`, and `jobs-*.json`.
- Merge: `logs/remerge.txt`, `logs/conflicts.txt`, `logs/lost-lines.txt`, `logs/qr-identity.txt`.
- Next sync and release: `rename.patch`, `logs/next-sync.txt`, `logs/release-replay.txt`.
- Photos: `e2e/e2e.php` (the #37 QA probe; the stub save hook was in the QA copy only), `logs/photo-*.json`,
  `logs/photo-vs-pr37.txt`.
- Suites and scans: `logs/suites.txt`, `logs/scans.txt`.
