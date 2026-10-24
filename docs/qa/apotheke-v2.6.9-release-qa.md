# v2.6.9 release QA

Release: [v2.6.9](https://github.com/thordin9/apotheke/releases/tag/v2.6.9), cut automatically from main after PR
[#80](https://github.com/thordin9/apotheke/pull/80) (idle sign-in limit `session_idle_hours`, SEC-N6) merged as
`ded4dfab31f905b6f3489a4f0642b10587dcb01c`, pinned to `11b4d1f`. Previous release: v2.6.8.

**Verdict: PASS.** No High, Medium or Low findings. One Nit and five Info notes.

## Scope

Under the project's compute rule, this is a **diff-read release QA**. v2.6.9 is one release commit (`VERSION`,
`CHANGELOG.md`, `README.md` coverage table) on top of #80, which I fully reviewed and passed
(`docs/qa/apotheke-pr80-session-idle-hours-qa.md`) and hash-checked at its merge head `11b4d1f`.
I did **not** rebuild the image, run the test suites, use PostgreSQL or Docker, or run mutations.

Sources: a read-only clone in a `mktemp` scratch dir (deleted afterwards), and the GitHub API for the release and
Actions runs. Nothing was posted. Times are Athens time (EEST, UTC+3).

## Test plan

1. Tag and parent chain: annotated tag on the release commit; its only parent `ded4dfa`; `ded4dfa`'s parents
   `d821bb8` and `11b4d1f`; merged tree identical to `11b4d1f`.
2. The release commit's diff: only release files.
3. `v2.6.8...v2.6.9`: excluding release files, identical to `d821bb8..11b4d1f`; QA docs byte-identical to originals.
4. Main CI on `ded4dfa` and the Release run.
5. Release body against CHANGELOG `[2.6.9]`, compare links, version, duplicate bullets.
6. CHANGELOG: `[2.6.8]` and older, and the older link references, byte-identical to the `v2.6.8` tag's.
7. Upgrade-relevant behaviour change and its documentation.

## Results

### 1. Tag and parent chain

- Tag `v2.6.9` is an **annotated** tag, tagger github-actions[bot], message `v2.6.9`, 16:43 EEST, unsigned like
  the earlier tags.
- It points at `3f729a3a54ece3e081b8dc4d885467f766a3f0dc`, `chore(release): v2.6.9 [skip ci]` by
  github-actions[bot], whose **only parent is `ded4dfa`**. `3f729a3` was the head of `main` when checked.
- `ded4dfa` (`Merge pull request #80`, 16:32:41 EEST) has parents **`d821bb8`** (the v2.6.8 release commit) and
  **`11b4d1f`**, the head I hash-checked.
- `git diff 11b4d1f ded4dfa` is **empty**.

### 2. Release commit

`3f729a3` changes exactly three files (+16 / −6):

- `VERSION`: `2.6.8` to `2.6.9`.
- `CHANGELOG.md`: the `## [2.6.9] - 2026-10-24` header above #80's one handwritten `### Fixed` bullet, four
  generated `### Maintenance` lines, the new `[2.6.9]` compare link and the moved `[Unreleased]` link.
- `README.md`: coverage table refresh only (Control Panel settings, Database/migrations, Bootstrap rows and the
  All PHP row, 7215 to 7236 lines).

### 3. The v2.6.8...v2.6.9 diff

- 9 commits ahead, 0 behind.
- 24 files changed (+651 / −31): #80's session code (`src/bootstrap.php`, `src/settings.php`), migration
  `0027_session_idle_hours.php`, docs, tests, eight visual baselines, two QA docs, and the release files.
- Excluding `VERSION`, `CHANGELOG.md` and `README.md`, the `v2.6.8...v2.6.9` patch is **identical** to
  `git diff d821bb8 11b4d1f`. So the released #80 code is the code I reviewed.
- QA docs at the tag, `cmp`-identical to my originals:
  - `docs/qa/apotheke-pr80-session-idle-hours-qa.md` (26,172 B, sha256 `d085aed9…b736`)
  - `docs/qa/apotheke-v2.6.8-release-qa.md` (6,280 B, sha256 `f8078b9a…9254`)
- Doc order on #80 (v2.6.8 release doc last, #80 doc before it) was accepted by the PM; both are docs-only and
  follow the merge, so no code lands after the reviewed head.

### 4. Main CI and the Release run

CI run **40098599295** on `ded4dfa` (push to main): **success**, 16:32:45 to 16:43:00 EEST.

Release run **40099338764** (workflow_run, head `ded4dfa`): **success**, 16:43:02 to 16:43:20 EEST. No run for
`3f729a3` (`[skip ci]`), as expected.

Release id 422756272: published 16:43:16 EEST, not a draft or a prerelease, name `v2.6.9`, target `main`.

### 5. Release body

- `### Fixed` has **one** line, #80's handwritten bullet: sessions end after `session_idle_hours` hours with no
  request (default 168, at most 8760), stay-signed-in limit unchanged, settable under Users & sign-in and a sign-in
  setting on import, restore and the CLI; SEC-N6. It links #80. **No duplicate generated line** and no duplicate
  line anywhere in the body.
- `### Maintenance` has four generated lines from #80's docs/test commit subjects. All link #80.
- The body up to `**Full changelog:**` is **identical** to the CHANGELOG `[2.6.9]` section (ignoring blank lines).
- **Links and versions:** the body ends with `**Full changelog:** …/compare/v2.6.8...v2.6.9`. The CHANGELOG adds
  `[2.6.9]: …/compare/v2.6.8...v2.6.9` and moves `[Unreleased]` to `…/compare/v2.6.9...HEAD`. `## [Unreleased]` is
  empty. `VERSION` at the tag is `2.6.9`.

### 6. CHANGELOG history

Comparing the v2.6.9 and v2.6.8 tags' `CHANGELOG.md`:

- Everything from `## [2.6.8]` to the link references (40,124 B) is **byte-identical**.
- Link references: apart from the changed `[Unreleased]` and the new `[2.6.9]`, all are byte-identical.

### 7. Upgrade-relevant behaviour

- Migration `0027_session_idle_hours` inserts `session_idle_hours = 168` only if the key is absent (idempotent,
  plain SQL, no schema change). It runs automatically on upgrade.
- After upgrade, a signed-in session with no request for 7 days ends; users idle longer than that sign in again.
  `session_days` (default 30) is unchanged.
- Covered in `README.md`, `docs/configuration.md`, `docs/control-panel.md`, `docs/user-guide.md`,
  `docs/architecture.md` and the CHANGELOG bullet. There is no `config.php` key. No operator action is required.

## Security

- The released tree is byte-identical to #80's reviewed and hash-checked head plus the three release files (§1–§3).
- The release commit adds no secrets, tokens or hosts. CI is green on `ded4dfa`.
- This doc contains no secrets, tokens or private hostnames.

## Findings

### High

None.

### Medium

None.

### Low

None.

### Nit

- **N-1: Maintenance noise.** Four Maintenance lines, two of them just QA-doc commits and one CHANGELOG
  housekeeping. Same by-design generator behaviour as v2.6.5–v2.6.8 N-1.

### Info

- **I-1: reduced scope.** Diff-read QA under the compute rule: no image rebuild, suite, PostgreSQL, Docker or
  mutation run. My #80 review covers runtime behaviour; §1–§3 show the released tree adds only the release files.
- **I-2: behaviour change.** The idle limit (default 168 h) ends sessions idle for 7 days or more that v2.6.8 kept
  alive up to `session_days`. Documented (§7); no explicit "upgrade note" section, but none is needed since no
  operator action is required.
- **I-3: README coverage refreshed.** All PHP stays at 95.7 % combined over 7236 lines. Not re-measured here.
- **I-4: #80's known findings ship as reviewed.** #80 QA L-1 (`session_days` is only the cookie expiry; there is
  no server-side absolute check, so the "at most `session_days`" wording relies on the browser honouring the
  cookie), L-2 (mutants M13/M17 survive), N-1..N-4 and I-1 ("sign out everywhere" not on main) are in v2.6.9
  unchanged. L-1, L-2, N-1..N-3 and I-1 are queued by the PM as the next main fix.
- **I-5: doc order.** On #80 the v2.6.8 release QA doc is the last commit, after #80's own QA doc, instead of the
  usual order. Accepted by the PM to avoid a force-push; no effect on the released code.
