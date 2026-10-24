# v2.6.6 release QA

Release: [v2.6.6](https://github.com/thordin9/apotheke/releases/tag/v2.6.6), cut automatically from main after PR
[#77](https://github.com/thordin9/apotheke/pull/77) (CLI admin with guest browsing) merged as
`4dd79fe3dde45762a83e8b6bac0dcc9083887bf6`, pinned to `80814d9`. Previous release: v2.6.5.

**Verdict: PASS.** No High, Medium or Low findings. One Nit and four Info notes.

## Scope

Under the project's compute rule, this is a **diff-read release QA**. v2.6.6 is one release commit (`VERSION`,
`CHANGELOG.md`) on top of #77, which I fully reviewed and passed at `ad21bf1` and hash-checked at `80814d9`
(`docs/qa/apotheke-pr77-cli-admin-guest-qa.md`). I did **not** rebuild the image, run the test suites, use
PostgreSQL or Docker, or run mutations.

Sources: a read-only clone in a `mktemp` scratch dir (deleted afterwards), and the GitHub API for the release and
Actions runs. Nothing was posted. Times are Athens time (EEST, UTC+3).

## Test plan

1. Tag and parent chain: annotated tag on the release commit; its parent `4dd79fe`; `4dd79fe`'s parents `351e88e`
   and `80814d9`; merged tree identical to `80814d9`.
2. The release commit's diff: only release files.
3. `v2.6.5...v2.6.6`: exactly #77's files, its QA doc (byte-identical to the original) and the release files; and
   `ad21bf1..80814d9` is only the main merge (v2.6.5) plus the QA doc.
4. Main CI on `4dd79fe` and the Release run.
5. Release body against CHANGELOG `[2.6.6]`, compare links, version; check for the v2.6.5 L-1 duplicate pattern.
6. CHANGELOG: `[2.6.5]` and older, and the older link references, byte-identical to the `v2.6.5` tag's.

## Results

### 1. Tag and parent chain

- Tag `v2.6.6` is an **annotated** tag, tagger github-actions[bot], message `v2.6.6`, 15:02:50 EEST, unsigned like
  the earlier tags.
- It points at `d9eb204fea053b584073af17fe5cc29036f7cfbd`, `chore(release): v2.6.6 [skip ci]` by
  github-actions[bot], whose **only parent is `4dd79fe`**. `d9eb204` was the head of `main` when checked.
- `4dd79fe` has parents **`351e88e`** (the v2.6.5 release commit) and **`80814d9`**, the head I hash-checked.
- `git diff 80814d9 4dd79fe` is **empty**.

### 2. Release commit

`d9eb204` changes exactly two files (+11 / −2):

- `VERSION`: `2.6.5` to `2.6.6`.
- `CHANGELOG.md`: the `## [2.6.6] - 2026-10-24` header above the folded Unreleased text (one handwritten `### Fixed`
  bullet), three generated `### Maintenance` lines, the new `[2.6.6]` compare link and the moved `[Unreleased]` link.
- `README.md` is not touched (byte-identical to `4dd79fe`). See I-3.

### 3. The v2.6.5...v2.6.6 diff

- 8 commits ahead, 0 behind.
- 6 files changed (+272 / −3): `src/users.php`, `tests/Unit/SettingsTest.php`, `tests/Unit/UsersTest.php`, the #77
  QA doc, `VERSION` and `CHANGELOG.md`.
- Excluding the release files, the `v2.6.5...v2.6.6` patch is **identical** to `git diff 351e88e 80814d9`.
  No migration, Dockerfile, workflow or docs change beyond the QA doc and CHANGELOG.
- From my PASS head `ad21bf1` to `80814d9`: excluding the QA doc and `CHANGELOG.md`, the diff is **identical** to
  main's `205ed23..351e88e` (v2.6.5); `CHANGELOG.md` differs only by main's `[2.6.5]` section and link moves. So the
  released #77 code is the code I reviewed.
- QA doc at the tag, `cmp`-identical to `/workspace/qa-docs`:
  - `docs/qa/apotheke-pr77-cli-admin-guest-qa.md`: 16,238 B, sha256 `c750242f…0cce7`.

### 4. Main CI and the Release run

CI run **40091636233** on `4dd79fe` (push to main, attempt 1): **success**, 14:52:26 to 15:02:39 EEST.

Release run **40092311025** (workflow_run, head `4dd79fe`, attempt 1): **success**, 15:02:41 to 15:02:54 EEST. No
run exists for `d9eb204` (`[skip ci]`), as expected.

Release id 422706533: published 15:02:52 EEST, not a draft or a prerelease, name `v2.6.6`, author
github-actions[bot], target `main`, no assets. Not hand-edited (`updated_at` matches publication).

### 5. Release body

- `### Fixed` has **one** line, #77's handwritten auth bullet. **No duplicate generated line**: #77 had no `fix:`
  commit subject besides the merge, so the v2.6.5 L-1 pattern does not recur.
- `### Maintenance` has three generated lines from #77's docs commit subjects. All lines link #77.
- No #72 or older bullet was carried over; no upgrade note (none needed, I-2).
- The body up to `**Full changelog:**` is **identical** to the CHANGELOG `[2.6.6]` section.
- **Links and versions:** the body ends with `**Full changelog:** …/compare/v2.6.5...v2.6.6`. The CHANGELOG adds
  `[2.6.6]: …/compare/v2.6.5...v2.6.6` and moves `[Unreleased]` to `…/compare/v2.6.6...HEAD`. `## [Unreleased]` is
  empty. `VERSION` at the tag is `2.6.6`.

### 6. CHANGELOG history

Comparing the v2.6.6 and v2.6.5 tags' `CHANGELOG.md`:

- The preamble up to `## [Unreleased]` is byte-identical.
- Everything from `## [2.6.5]` to the link references (36,272 B) is **byte-identical**.
- Link references: apart from the changed `[Unreleased]` and the new `[2.6.6]`, all are byte-identical.

## Security

- The released tree is byte-identical to #77's reviewed and hash-checked head plus the two release files (§1–§3).
- The release notes describe the behaviour only.
- The release commit adds no secrets, tokens or hosts. CI is green on `4dd79fe`.
- This doc contains no secrets, tokens or private hostnames.

## Findings

### High

None.

### Medium

None.

### Low

None.

### Nit

- **N-1: Maintenance noise.** Two of the three Maintenance lines are CHANGELOG housekeeping ("note the CLI
  guest-browsing admin fix", "keep the CLI admin note out of v2.6.4"). Same by-design behaviour as v2.6.5 N-1.

### Info

- **I-1: reduced scope.** Diff-read QA under the compute rule: no image rebuild, suite, PostgreSQL, Docker or
  mutation run. My #77 review covers runtime behaviour; §1–§3 show the released tree adds only the release files.
- **I-2: no migration.** No `migrations/` or schema change, so an upgrade needs no database step and no upgrade note.
- **I-3: no README coverage refresh.** Unlike v2.6.5, the release commit leaves `README.md` unchanged. #77 adds only
  a few lines in `src/users.php`, so the table is not materially stale. Not re-measured here.
- **I-4: #77's known Lows ship unchanged.** #77 QA L-1 (the v2.6.5 duplicate Fixed bullet, now fixed in practice by
  authors using non-`fix` types; the generator change is queued in DevOps's #75 follow-up), N-1 and N-2 are in v2.6.6
  as reviewed; the follow-up fix PR is #79.
