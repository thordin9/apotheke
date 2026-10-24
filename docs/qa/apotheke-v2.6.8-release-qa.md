# v2.6.8 release QA

Release: [v2.6.8](https://github.com/thordin9/apotheke/releases/tag/v2.6.8), cut automatically from main after PR
[#79](https://github.com/thordin9/apotheke/pull/79) (26 MP PNG cap and failed-upload clean-up) merged as
`09ff554040abbed7420537aa29db5ed9d3b8c243`, pinned to `5885368`. Previous release: v2.6.7.

**Verdict: PASS.** No High, Medium or Low findings. One Nit and four Info notes.

## Scope

Under the project's compute rule, this is a **diff-read release QA**. v2.6.8 is one release commit (`VERSION`,
`CHANGELOG.md`, `README.md` coverage table) on top of #79, which I fully reviewed and passed
(`docs/qa/apotheke-pr79-png-cap-upload-cleanup-qa.md`) and hash-checked at its merge head `5885368`.
I did **not** rebuild the image, run the test suites, use PostgreSQL or Docker, or run mutations.

Sources: a read-only clone in a `mktemp` scratch dir (deleted afterwards), and the GitHub API for the release and
Actions runs. Nothing was posted. Times are Athens time (EEST, UTC+3).

## Test plan

1. Tag and parent chain: annotated tag on the release commit; its only parent `09ff554`; `09ff554`'s parents
   `c4e9eda` and `5885368`; merged tree identical to `5885368`.
2. The release commit's diff: only release files.
3. `v2.6.7...v2.6.8`: excluding release files, identical to `c4e9eda..5885368`; QA docs byte-identical to originals.
4. Main CI on `09ff554` and the Release run.
5. Release body against CHANGELOG `[2.6.8]`, compare links, version, duplicate bullets.
6. CHANGELOG: `[2.6.7]` and older, and the older link references, byte-identical to the `v2.6.7` tag's.

## Results

### 1. Tag and parent chain

- Tag `v2.6.8` is an **annotated** tag, tagger github-actions[bot], message `v2.6.8`, 16:17 EEST, unsigned like
  the earlier tags.
- It points at `d821bb8895884920193a7c054abf4fbe010cb9c1`, `chore(release): v2.6.8 [skip ci]` by
  github-actions[bot], whose **only parent is `09ff554`**. `d821bb8` was the head of `main` when checked.
- `09ff554` (`Merge pull request #79`, 16:06:47 EEST) has parents **`c4e9eda`** (the v2.6.7 release commit) and
  **`5885368`**, the head I hash-checked.
- `git diff 5885368 09ff554` is **empty**.

### 2. Release commit

`d821bb8` changes exactly three files (+18 / −5):

- `VERSION`: `2.6.7` to `2.6.8`.
- `CHANGELOG.md`: the `## [2.6.8] - 2026-10-24` header above #79's one handwritten `### Fixed` bullet, seven
  generated `### Maintenance` lines, the new `[2.6.8]` compare link and the moved `[Unreleased]` link.
- `README.md`: coverage table refresh only (Items row, Users & roles row and the All PHP row, 7166 to 7215 lines).

### 3. The v2.6.7...v2.6.8 diff

- 13 commits ahead, 0 behind.
- 19 files changed (+1089 / −63): #79's image code, docs, tests, four QA docs, and the release files.
- Excluding `VERSION`, `CHANGELOG.md` and `README.md`, the `v2.6.7...v2.6.8` patch is **identical** to
  `git diff c4e9eda 5885368`. So the released #79 code is the code I reviewed.
- QA docs at the tag, `cmp`-identical to my originals:
  - `docs/qa/apotheke-pr79-png-cap-upload-cleanup-qa.md` (25,609 B, sha256 `6a6ad75c…50dc9`)
  - `docs/qa/apotheke-v2.6.5-release-qa.md` (7,644 B)
  - `docs/qa/apotheke-v2.6.6-release-qa.md` (6,487 B)
  - `docs/qa/apotheke-v2.6.7-release-qa.md` (6,489 B)

### 4. Main CI and the Release run

CI run **40096741395** on `09ff554` (push to main): **success**, 16:06:50 to 16:17:02 EEST.

Release run **40097469586** (workflow_run, head `09ff554`): **success**, 16:17:04 to 16:17:22 EEST. No run for
`d821bb8` (`[skip ci]`), as expected.

Release id 422743611: published 16:17:18 EEST, not a draft or a prerelease, name `v2.6.8`, no assets. Not
hand-edited (`updated_at` matches publication).

### 5. Release body

- `### Fixed` has **one** line, #79's handwritten bullet. It states the **26 megapixel PNG** limit (WebP and GIF
  stay at 40), the clean-up of a camera file when processing dies, and suggest's 422. It links #79. **No duplicate
  generated line** and no duplicate line anywhere in the body.
- `### Maintenance` has seven generated lines from #79's test/docs commit subjects. All link #79.
- The body up to `**Full changelog:**` is **identical** to the CHANGELOG `[2.6.8]` section (ignoring blank lines).
- **Links and versions:** the body ends with `**Full changelog:** …/compare/v2.6.7...v2.6.8`. The CHANGELOG adds
  `[2.6.8]: …/compare/v2.6.7...v2.6.8` and moves `[Unreleased]` to `…/compare/v2.6.8...HEAD`. `## [Unreleased]` is
  empty. `VERSION` at the tag is `2.6.8`.

### 6. CHANGELOG history

Comparing the v2.6.8 and v2.6.7 tags' `CHANGELOG.md`:

- Everything from `## [2.6.7]` to the link references (38,777 B) is **byte-identical**.
- Link references: apart from the changed `[Unreleased]` and the new `[2.6.8]`, all are byte-identical.

## Security

- The released tree is byte-identical to #79's reviewed and hash-checked head plus the three release files (§1–§3).
- The release commit adds no secrets, tokens or hosts. CI is green on `09ff554`.
- This doc contains no secrets, tokens or private hostnames.

## Findings

### High

None.

### Medium

None.

### Low

None.

### Nit

- **N-1: Maintenance noise.** Seven Maintenance lines, four of them just QA-doc commits and two CHANGELOG
  housekeeping. Same by-design generator behaviour as v2.6.5–v2.6.7 N-1.

### Info

- **I-1: reduced scope.** Diff-read QA under the compute rule: no image rebuild, suite, PostgreSQL, Docker or
  mutation run. My #79 review covers runtime behaviour; §1–§3 show the released tree adds only the release files.
- **I-2: behaviour change.** The 26 MP PNG cap refuses some PNGs (26–40 MP) that v2.6.7 accepted. The user guide,
  README, admin guide, `docs/autofill.md` and `docs/deployment-and-operations.md` state it. No migration or schema
  change, and no operator action needed, so no upgrade note is required.
- **I-3: README coverage refreshed.** Items row 95.9 % to 95.4 % combined; All PHP stays at 95.7 % combined over
  7215 lines. Not re-measured here.
- **I-4: #79's known findings ship as reviewed.** #79 QA L-1 (a 40 MP WebP can peak at about 505 MB RSS, about
  twice `memory_limit`; not new in #79) and N-1..N-3 are in v2.6.8 unchanged; they are queued for Coder's main
  follow-up (L-1 as a `fix(images):` commit).
