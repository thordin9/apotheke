# v2.6.7 release QA

Release: [v2.6.7](https://github.com/thordin9/apotheke/releases/tag/v2.6.7), cut automatically from main after PR
[#75](https://github.com/thordin9/apotheke/pull/75) (Docker localhost port and required sign-in, SEC-M3) merged as
`077f60ba0216e72ec95a46eac8435d9c088a4f3a`, pinned to `4864ed1`. Previous release: v2.6.6.

**Verdict: PASS.** No High, Medium or Low findings. One Nit and four Info notes.

## Scope

Under the project's compute rule, this is a **diff-read release QA**. v2.6.7 is one release commit (`VERSION`,
`CHANGELOG.md`, `README.md` coverage table) on top of #75, which I fully reviewed and passed
(`docs/qa/apotheke-pr75-docker-localhost-signin-qa.md`) and diff-read and hash-checked at its merge head `4864ed1`.
I did **not** rebuild the image, run the test suites, use PostgreSQL or Docker, or run mutations.

Sources: a read-only clone in a `mktemp` scratch dir (deleted afterwards), and the GitHub API for the release and
Actions runs. Nothing was posted. Times are Athens time (EEST, UTC+3).

## Test plan

1. Tag and parent chain: annotated tag on the release commit; its only parent `077f60b`; `077f60b`'s parents
   `d9eb204` and `4864ed1`; merged tree identical to `4864ed1`.
2. The release commit's diff: only release files.
3. `v2.6.6...v2.6.7`: excluding release files, identical to `d9eb204..4864ed1`; QA docs byte-identical to originals.
4. Main CI on `077f60b` and the Release run.
5. Release body against CHANGELOG `[2.6.7]`, upgrade note, compare links, version, duplicate bullets.
6. CHANGELOG: `[2.6.6]` and older, and the older link references, byte-identical to the `v2.6.6` tag's.

## Results

### 1. Tag and parent chain

- Tag `v2.6.7` is an **annotated** tag, tagger github-actions[bot], message `v2.6.7`, 15:26:40 EEST, unsigned like
  the earlier tags.
- It points at `c4e9eda356e6286a4d1e3991192fb886b16d4036`, `chore(release): v2.6.7 [skip ci]` by
  github-actions[bot], whose **only parent is `077f60b`**. `c4e9eda` was the head of `main` when checked.
- `077f60b` has parents **`d9eb204`** (the v2.6.6 release commit) and **`4864ed1`**, the head I hash-checked.
- `git diff 4864ed1 077f60b` is **empty**.

### 2. Release commit

`c4e9eda` changes exactly three files (+18 / −4):

- `VERSION`: `2.6.6` to `2.6.7`.
- `CHANGELOG.md`: the `## [2.6.7] - 2026-10-24` header above the folded Unreleased text (#75's upgrade note and its
  one handwritten `### Fixed` bullet), eight generated `### Maintenance` lines, the new `[2.6.7]` compare link and
  the moved `[Unreleased]` link.
- `README.md`: coverage table refresh only (Users & roles row and the All PHP row, 7153 to 7166 lines).

### 3. The v2.6.6...v2.6.7 diff

- 15 commits ahead, 0 behind.
- 23 files changed (+929 / −28): #75's Docker, entrypoint, `src/users.php`, `bin/settings.php`, docs, tests and
  CI files, three QA docs, and the release files.
- Excluding `VERSION`, `CHANGELOG.md` and `README.md`, the `v2.6.6...v2.6.7` patch is **identical** to
  `git diff d9eb204 4864ed1`. So the released #75 code is the code I reviewed.
- QA docs at the tag, `cmp`-identical to my originals:
  - `docs/qa/apotheke-pr75-docker-localhost-signin-qa.md` (25,840 B)
  - `docs/qa/apotheke-v2.6.3-release-qa.md`
  - `docs/qa/apotheke-v2.6.4-release-qa.md`

### 4. Main CI and the Release run

CI run **40093241699** on `077f60b` (push to main): **success**, 15:16:17 to 15:26:30 EEST.

Release run **40093937837** (workflow_run, head `077f60b`): **success**, 15:26:31 to 15:26:45 EEST. No run for
`c4e9eda` (`[skip ci]`), as expected.

Release id 422717760: published 15:26:42 EEST, not a draft or a prerelease, name `v2.6.7`, target `main`, no
assets. Not hand-edited (`updated_at` matches publication).

### 5. Release body

- The body **leads with #75's full upgrade note** (localhost-only port, how to expose it on purpose, the container
  refusing to start with sign-in off, `bin/settings.php password` or `ALLOW_NO_LOGIN=1`, and the new-install
  requirement). This is an upgrade-affecting fix, so the note is required and present.
- `### Fixed` has **one** line, #75's handwritten bullet. **No duplicate generated line** and no duplicate line
  anywhere in the body.
- `### Maintenance` has eight generated lines from #75's test/docs commit subjects. All lines link #75.
- The body up to `**Full changelog:**` is **identical** to the CHANGELOG `[2.6.7]` section (ignoring the leading
  blank line).
- **Links and versions:** the body ends with `**Full changelog:** …/compare/v2.6.6...v2.6.7`. The CHANGELOG adds
  `[2.6.7]: …/compare/v2.6.6...v2.6.7` and moves `[Unreleased]` to `…/compare/v2.6.7...HEAD`. `## [Unreleased]` is
  empty. `VERSION` at the tag is `2.6.7`.

### 6. CHANGELOG history

Comparing the v2.6.7 and v2.6.6 tags' `CHANGELOG.md`:

- Everything from `## [2.6.6]` to the link references (36,730 B) is **byte-identical**.
- Link references: apart from the changed `[Unreleased]` and the new `[2.6.7]`, all are byte-identical.

## Security

- The released tree is byte-identical to #75's reviewed and hash-checked head plus the three release files (§1–§3).
- The upgrade note uses placeholders (`<LAN-IP>`) and contains no secrets; `APP_PASSWORD` is named, never valued.
- The release commit adds no secrets, tokens or hosts. CI is green on `077f60b`.
- This doc contains no secrets, tokens or private hostnames.

## Findings

### High

None.

### Medium

None.

### Low

None.

### Nit

- **N-1: Maintenance noise.** Eight Maintenance lines, three of them just QA-doc commits and one CHANGELOG
  housekeeping. Same by-design generator behaviour as v2.6.5 N-1 and v2.6.6 N-1.

### Info

- **I-1: reduced scope.** Diff-read QA under the compute rule: no image rebuild, suite, PostgreSQL, Docker or
  mutation run. My #75 review covers runtime behaviour; §1–§3 show the released tree adds only the release files.
- **I-2: upgrade-affecting fix.** Docker now binds `127.0.0.1` and requires sign-in (or `ALLOW_NO_LOGIN=1`). No
  migration or schema change; the upgrade step is the operator action in the upgrade note, which the release states.
- **I-3: README coverage refreshed.** Users & roles branch coverage moves 94.9 % to 89.9 %, from #75's new
  `auth_on_at_start()` paths. Not re-measured here.
- **I-4: #75's known Lows ship as reviewed.** #75 QA L-1 (no test for the entrypoint's exit 78), L-2 (`APP_PASSWORD`
  has no 8-character minimum) and N-1..N-3 are in v2.6.7 unchanged; they are queued for DevOps's #75 follow-up PR.
