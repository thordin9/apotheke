# v2.6.10 release QA

Release: [v2.6.10](https://github.com/thordin9/apotheke/releases/tag/v2.6.10), cut automatically from main after PR
[#81](https://github.com/thordin9/apotheke/pull/81) (SEC-M3 follow-up hardening: 8-character `APP_PASSWORD`,
5 s PostgreSQL connect timeout in the start check, `bin/settings.php` exit 1, ImageMagick policy check, nginx 421
body, release-generator dedupe) merged as `50548a1807ca599ebc22770f0ac1eeabcacbfff1`, pinned to `f8fcfc0`.
Previous release: v2.6.9 (`3f729a3`).

**Verdict: PASS.** No High, Medium or Low findings. Two Nits and seven Info notes.

## Scope

Under the project's compute rule, this is a **diff-read release QA**. v2.6.10 is one release commit (`VERSION`,
`CHANGELOG.md`, `README.md` coverage table) on top of #81, which I fully reviewed and passed
(`docs/qa/apotheke-pr81-sec-m3-followup-qa.md`) and hash-checked at its merge head `f8fcfc0`.
I did **not** rebuild the image, run the test suites, use PostgreSQL or Docker, or run mutations.

Sources: a read-only clone in a `mktemp` scratch dir (deleted afterwards), and the GitHub API (read-only) for the
release and Actions runs. Nothing was posted. Times are Athens time (EEST, UTC+3).

## Test plan

1. Tag and parent chain: annotated tag on the release commit; its only parent `50548a1`; `50548a1`'s parents
   `3f729a3` and `f8fcfc0`; merged tree identical to `f8fcfc0`.
2. The release commit's diff: only release files.
3. `v2.6.9...v2.6.10`: excluding release files, identical to `3f729a3..f8fcfc0`; QA docs byte-identical to originals.
4. Main CI on `50548a1` and the Release run.
5. Release body against CHANGELOG `[2.6.10]`, compare links, version, duplicate bullets, first use of the
   generator's Fixed dedupe.
6. CHANGELOG: `[2.6.9]` and older, and the older link references, byte-identical to the `v2.6.9` tag's.
7. Upgrade-relevant behaviour changes and their documentation.

## Results

### 1. Tag and parent chain

- Tag `v2.6.10` is an **annotated** tag (object `537c09f`), tagger github-actions[bot], message `v2.6.10`,
  17:47 EEST, unsigned like the earlier tags.
- It points at `32b1ef87aed0e16c17d0ac299ba457c227281b0f`, `chore(release): v2.6.10 [skip ci]` by
  github-actions[bot], whose **only parent is `50548a1`**. `32b1ef8` was the head of `main` when checked.
- `50548a1` (`Merge pull request #81`, 17:36:41 EEST) has parents **`3f729a3`** (the v2.6.9 release commit) and
  **`f8fcfc0`**, the head I hash-checked.
- `git diff f8fcfc0 50548a1` is **empty**.

### 2. Release commit

`32b1ef8` changes exactly three files (+22 / −5):

- `VERSION`: `2.6.9` to `2.6.10`.
- `CHANGELOG.md`: the `## [2.6.10] - 2026-10-24` header placed above #81's handwritten upgrade note, three `### Fixed`
  bullets and one `### Maintenance` bullet; 14 generated `### Maintenance` lines; the new `[2.6.10]` compare link and
  the moved `[Unreleased]` link.
- `README.md`: coverage table refresh only (Users & roles, Database/migrations rows and the All PHP row, 7236 to
  7244 lines).

### 3. The v2.6.9...v2.6.10 diff

- 23 commits ahead, 0 behind.
- 26 files changed (+927 / −69): #81's code (`bin/migrate.php`, `bin/settings.php`, `docker/entrypoint.sh`,
  `docker/nginx.conf`, `scripts/imagick-policy-check.php`, `scripts/release.php`, `src/db.php`, `src/settings.php`,
  `src/users.php`), the Docker check scripts, docs, tests, one visual spec, two QA docs, and the release files.
- Excluding `VERSION`, `CHANGELOG.md` and `README.md`, the `v2.6.9...v2.6.10` patch is **identical** to
  `git diff 3f729a3 f8fcfc0` (23 files, +888 / −61). So the released #81 code is the code I reviewed. `README.md`
  differs from `f8fcfc0` only in the 6 coverage-table lines of §2.
- QA docs at the tag, `cmp`-identical to my originals:
  - `docs/qa/apotheke-pr81-sec-m3-followup-qa.md` (32,941 B, sha256 `91e11351…a258`)
  - `docs/qa/apotheke-v2.6.9-release-qa.md` (7,443 B, sha256 `057c4ddf…6a98`)
- Doc order on #81 is the usual one: v2.6.9 release doc (`56c8300`), then #81's doc last (`f8fcfc0`). Both are
  docs-only and follow the reviewed code.

### 4. Main CI and the Release run

CI run **40103273136** on `50548a1` (push to main): **success**, 17:36:45 to 17:46:47 EEST.

Release run **40104019912** (workflow_run, head `50548a1`): **success**, 17:46:49 to 17:47:06 EEST. No run for
`32b1ef8` (`[skip ci]`), as expected.

Release id 422790018: published 17:47:03 EEST, not a draft or a prerelease, name `v2.6.10`, target `main`.

### 5. Release body

- The **upgrade note comes first**, before `### Fixed`: a new install's `APP_PASSWORD` needs at least 8
  characters, not only spaces; a shorter one stops the first start without showing it; an install that already
  has an account is unaffected.
- `### Fixed` has **three** handwritten lines, all linking #81: **auth** (8-character minimum enforced by the Docker
  start check with exit 78, `bin/migrate.php`, `bin/settings.php` with exit 1 and no stack trace, and the seed;
  value never logged), **docker** (unreadable-database test, 5 s PostgreSQL wait per try, ImageMagick policy check,
  nginx 421 body) and **release** (the dedupe itself).
- **Dedupe works.** #81 has six `fix:` commits (`1e3df2f`, `8dd3980`, `0ea0364`, `0794ca8`, `20e8c88`, `5f3eec8`)
  across scopes `release`, `auth`, `docker` and `security`. **None** produced a generated line, including
  `fix(security)`, whose scope differs from every handwritten bullet ("whatever their scope").
- `### Maintenance` has 14 generated lines from #81's `docs:`/`test:` subjects plus #81's handwritten docs bullet
  last. All link #81. **No duplicate line** anywhere in the body (`sort | uniq -d` empty).
- The body up to `**Full changelog:**` is **identical** to the CHANGELOG `[2.6.10]` section (ignoring blank lines).
- **Links and versions:** the body ends with `**Full changelog:** …/compare/v2.6.9...v2.6.10`. The CHANGELOG adds
  `[2.6.10]: …/compare/v2.6.9...v2.6.10` and moves `[Unreleased]` to `…/compare/v2.6.10...HEAD`.
  `## [Unreleased]` is empty. `VERSION` at the tag is `2.6.10`.

### 6. CHANGELOG history

Comparing the v2.6.10 and v2.6.9 tags' `CHANGELOG.md`:

- Everything from `## [2.6.9]` to the link references (40,922 B) is **byte-identical** (sha256 `07ad7e68…9ea4`
  on both).
- Link references: apart from the changed `[Unreleased]` and the new `[2.6.10]`, all are byte-identical.

### 7. Upgrade-relevant behaviour

- **`APP_PASSWORD` minimum.** At least 8 characters, not only spaces. It only matters while no account exists
  (first start, or a data dir with no accounts): a shorter value then stops the Docker start check (exit 78),
  `bin/migrate.php`, `bin/settings.php` (exit 1) and the seed, without printing the value. An existing install
  that still sets a short `APP_PASSWORD` but already has an account is unaffected. Covered by the upgrade note,
  the auth Fixed bullet, the README env table, `docs/configuration.md` and `docs/deployment-and-operations.md`.
- **PostgreSQL connect timeout.** Each start-check try waits at most 5 s; with 30 tries 1 s apart, an unreachable
  PostgreSQL now takes up to about 3 minutes before the container exits. Covered by the docker Fixed bullet and
  `docs/deployment-and-operations.md`.
- **`bin/settings.php` exit 1.** Errors print the message only and exit 1 with no stack trace. Covered by the auth
  Fixed bullet, `docs/configuration.md` and `docs/deployment-and-operations.md`.
- No migration, no `config.php` key, no schema change. No operator action is needed unless a no-account install
  sets an `APP_PASSWORD` shorter than 8 characters.

## Security

- The released tree is byte-identical to #81's reviewed and hash-checked head plus the three release files (§1–§3).
- The release commit adds no secrets, tokens or hosts. CI is green on `50548a1`.
- This doc contains no secrets, tokens or private hostnames.

## Findings

### High

None.

### Medium

None.

### Low

None.

### Nit

- **N-1: Maintenance noise.** 14 generated Maintenance lines, two of them QA-doc commits and one CHANGELOG
  housekeeping. Same by-design generator behaviour as v2.6.5–v2.6.9 N-1.
- **N-2: Maintenance overlap.** Five generated `docs:` lines (`auth`, `docker`, two `hosts`, `health`) restate
  what #81's handwritten docs Maintenance bullet already says. Not literal duplicates, and by design: the new
  dedupe only covers handwritten Fixed bullets against `fix:` commits. Extending it to Maintenance is optional.

### Info

- **I-1: reduced scope.** Diff-read QA under the compute rule: no image rebuild, suite, PostgreSQL, Docker or
  mutation run. My #81 review covers runtime behaviour; §1–§3 show the released tree adds only the release files.
- **I-2: behaviour changes.** The `APP_PASSWORD` minimum can stop the first start of a no-account install that
  sets a shorter value; the 5 s connect timeout lengthens the worst-case start against an unreachable PostgreSQL to
  about 3 minutes; `bin/settings.php` errors exit 1. All documented, with an upgrade note first (§5, §7).
- **I-3: first dedupe release.** The generator dedupe behaved as specified on its first real release (§5).
  #81 N-4 (no test pinning "Fixed only") still applies.
- **I-4: README coverage refreshed.** All PHP stays at 95.7 % combined over 7244 lines. Not re-measured here.
- **I-5: #81's known findings ship as reviewed.** N-1 (character vs byte count, only accepting side tested),
  N-2 (`trim()` doesn't strip Unicode spaces), N-3 (no test for the 5 s connect timeout), N-4 (dedupe test doesn't
  pin "Fixed only"), N-5 (two rows still say `PUBLIC_URL`'s host is always accepted), N-6 (`AppPasswordTest` leaves
  temp data dirs) and N-7 (no exit-code test for the `UserError` side of `bin/settings.php`'s catch) are in
  v2.6.10 unchanged.
- **I-6: #81 I-9 ships as reviewed.** `bin/settings.php list` with a short `APP_PASSWORD` after the accounts are
  gone prints the settings lines, as without `APP_PASSWORD`; no value is shown.
- **I-7: follow-up.** #81 N-1 to N-7 and I-9 are queued for the follow-up PR, together with the #81 follow-ups
  already listed there.
