# v2.6.5 release QA

Release: [v2.6.5](https://github.com/thordin9/apotheke/releases/tag/v2.6.5), cut automatically from main after PR
[#72](https://github.com/thordin9/apotheke/pull/72) (image decode limits) merged as
`895b007e32146c9c1315c09f844008ea5975dc00`, pinned to `43919f7`. Previous release: v2.6.4.

**Verdict: PASS.** No High or Medium findings. One Low, one Nit and four Info notes.

## Scope

Under the project's compute rule, this is a **diff-read release QA**. v2.6.5 is one release commit (`VERSION`,
`CHANGELOG.md`, `README.md`) on top of #72, which I fully reviewed and passed at `a29e1ee` and hash-checked at
`43919f7` (`docs/qa/apotheke-pr72-image-decode-limits-qa.md`). I did **not** rebuild the image, run the test suites,
use PostgreSQL or Docker, or run mutations.

Sources: a read-only clone in a `mktemp` scratch dir (deleted afterwards), and the GitHub API for releases and
Actions runs. Nothing was posted. Times are Athens time (EEST, UTC+3).

## Test plan

1. Tag and parent chain: annotated tag on the release commit; its parent `895b007`; `895b007`'s parents `205ed23`
   and `43919f7`; merged tree identical to `43919f7`.
2. The release commit's diff: only release files.
3. `v2.6.4...v2.6.5`: exactly #72's files, its QA doc (byte-identical to the original) and the release files.
4. Main CI on `895b007` and the Release run.
5. Release body against CHANGELOG `[2.6.5]`, compare links, version.
6. CHANGELOG: `[2.6.4]` and older, and the older link references, byte-identical to the `v2.6.4` tag's.

## Results

### 1. Tag and parent chain

- Tag `v2.6.5` is an **annotated** tag, tagger github-actions[bot], message `v2.6.5`, 14:34:19 EEST, unsigned like
  the earlier tags.
- It points at `351e88e8665f2a2db651f3dd351e9638e0c3d79c`, `chore(release): v2.6.5 [skip ci]` by
  github-actions[bot], whose **only parent is `895b007`**. `351e88e` was the head of `main` when checked.
- `895b007` has parents **`205ed23`** (the v2.6.4 release commit) and **`43919f7`**, the head I hash-checked.
- `git diff 43919f7 895b007` is **empty**.

### 2. Release commit

`351e88e` changes exactly three files (+17 / −5):

- `VERSION`: `2.6.4` to `2.6.5`.
- `CHANGELOG.md`: the `## [2.6.5] - 2026-10-24` header above the folded Unreleased text, one generated `### Fixed`
  line, five generated `### Maintenance` lines, the new `[2.6.5]` compare link and the moved `[Unreleased]` link.
- `README.md`: the generated coverage table (Items row; Bootstrap row; All PHP now 7153 lines, 65.4 % / 90.2 % /
  95.8 %). See I-3.

### 3. The v2.6.4...v2.6.5 diff

- 17 commits ahead, 0 behind.
- 15 files changed (+1383 / −52): `composer.json`, `docs/autofill-admin-guide.md`, `docs/autofill.md`,
  `docs/deployment-and-operations.md`, `public/index.php`, `src/autofill.php`, `src/bootstrap.php`,
  `src/image-color.php`, `src/items.php`, `tests/Unit/ImageDecodeTest.php`, `tests/integration_autofill.php`, the
  #72 QA doc, `VERSION`, `CHANGELOG.md` and `README.md`.
- Excluding the three release files, the `v2.6.4...v2.6.5` patch is **identical** to `git diff 205ed23 43919f7`.
  #72 itself does not touch `README.md` or `VERSION`; its `CHANGELOG.md` change is the Unreleased bullets.
  No migration, Dockerfile or workflow change.
- QA doc at the tag, `cmp`-identical to `/workspace/qa-docs`:
  - `docs/qa/apotheke-pr72-image-decode-limits-qa.md`: 23,412 B, sha256 `d255f03d…69df1`.

### 4. Main CI and the Release run

CI run **40089804058** on `895b007` (push to main, attempt 1): **success**, 14:24:53 to 14:34:05 EEST.

| Job | Result |
|---|---|
| Lint | success |
| Secret scan | success |
| Tests (PHP 8.3, sqlite) | success |
| Tests (PHP 8.3, pgsql) | success |
| Headless mobile checks | success |
| Docker build + smoke test | success |
| Visual E2E (Playwright, shards 1–3) | success |
| Conventional Commits | skipped (push event; it runs on pull requests) |

Release run **40090423091** (workflow_run, head `895b007`, attempt 1): **success**, 14:34:07 to 14:34:25 EEST. No
run exists for `351e88e` (`[skip ci]`), as expected.

Release id 422693165: published 14:34:21 EEST, not a draft or a prerelease, name `v2.6.5`, author
github-actions[bot], target `main`, no assets. Not hand-edited (`updated_at` matches publication).

### 5. Release body

- `### Fixed` has **four** lines: #72's three handwritten bullets (S-M1, SEC-N5) plus one generated line from
  commit `d09ee4d` (`fix(autofill): refuse an over-limit photo on suggest`). See L-1.
- `### Maintenance` has five generated lines from #72's docs/test commit subjects. All lines link #72.
- No #73 or older bullet was carried over; there is no upgrade note (none needed, I-2).
- The body up to `**Full changelog:**` is **identical** to the CHANGELOG `[2.6.5]` section.
- **Links and versions:** the body ends with `**Full changelog:** …/compare/v2.6.4...v2.6.5`. The CHANGELOG adds
  `[2.6.5]: …/compare/v2.6.4...v2.6.5` and moves `[Unreleased]` to `…/compare/v2.6.5...HEAD`. `## [Unreleased]` is
  empty. `VERSION` at the tag is `2.6.5`.

### 6. CHANGELOG history

Comparing the v2.6.5 and v2.6.4 tags' `CHANGELOG.md`:

- The preamble up to `## [Unreleased]` is byte-identical.
- Everything from `## [2.6.4]` to the link references (34,265 B) is **byte-identical**.
- Link references: apart from the changed `[Unreleased]` and the new `[2.6.5]`, all are byte-identical.

## Security

- The released tree is byte-identical to #72's reviewed and hash-checked head plus the three release files (§1–§3).
- The release notes describe the limits only, with no reproduction files.
- The release commit adds no secrets, tokens or hosts. CI's Secret scan is green on `895b007`.
- This doc contains no secrets, tokens or private hostnames.

## Findings

### High

None.

### Medium

None.

### Low

- **L-1: `[2.6.5]` Fixed has a fourth, duplicate line.** The generator turned `d09ee4d`'s `fix(autofill):` subject
  into a Fixed line, "refuse an over-limit photo on suggest", which repeats the last sentence of the third
  handwritten bullet (`/autofill/suggest` gets 422). The text is accurate, but it reads as a separate fix and the
  section is not the expected three #72 bullets. The released `[2.6.5]` section should stay as it is; an optional
  hand edit of the release body could drop the line. For the future, the generator could skip `fix:` subjects when
  the PR already has a handwritten Fixed bullet, or authors could use a non-`fix` type for follow-up commits inside
  a fix PR.

### Nit

- **N-1: Maintenance noise.** Two of the five Maintenance lines are CHANGELOG housekeeping ("record the large-photo
  decode limits", "keep #72 notes under Unreleased"). Same by-design behaviour as v2.6.3 N-1 and v2.6.4 I-4.

### Info

- **I-1: reduced scope.** Diff-read QA under the compute rule: no image rebuild, suite, PostgreSQL, Docker or
  mutation run. My #72 review covers runtime behaviour; §1–§3 show the released tree adds only the release files.
- **I-2: no migration.** No `migrations/` or schema change, so an upgrade needs no database step and no upgrade note.
- **I-3: README coverage refresh.** Unlike v2.6.4, the release commit regenerates the README coverage table. The new
  figures (All PHP 7153 lines; integration 91.3 % to 90.2 %, combined 96.1 % to 95.8 %; Items combined 97.0 % to
  95.9 %) are consistent with #72 adding about 166 PHP lines, mostly in `src/image-color.php` and `src/items.php`.
  Not re-measured here.
- **I-4: #72's known Lows ship unchanged.** #72 QA L-1 (PNG decode memory, already on main before #72) and the
  ImageMagick JPEG resize note are in v2.6.5 as reviewed; the follow-up fix PR is queued.
