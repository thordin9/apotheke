# v2.6.4 release QA

Release: [v2.6.4](https://github.com/thordin9/apotheke/releases/tag/v2.6.4), cut automatically from main after PR
[#73](https://github.com/thordin9/apotheke/pull/73) (`fix(security): follow-up hardening docs and checks for #70`)
merged as `a94b76aa050481980f6bf744a946d73e87b8af9e`, pinned to `596fe97`. Previous release: v2.6.3.

**Verdict: PASS.** No High, Medium or Low findings. Two Nits and five Info notes.

## Scope

Under the project's compute rule, this is a **diff-read release QA**. v2.6.4 is one release commit (`VERSION`,
`CHANGELOG.md`) on top of #73, which I fully reviewed and passed at `959d0e1` and hash-checked at `596fe97`
(`docs/qa/apotheke-pr73-main-hardening-followups-qa.md`). So I did **not** rebuild the image, run nginx or php-fpm,
run the test suites, use PostgreSQL, run mutations or test an upgrade.

Sources: a read-only clone over `https://github.com` in a `mktemp` scratch dir (deleted afterwards), and the GitHub
API for releases and Actions runs. Nothing was posted. Times are Athens time (EEST, UTC+3).

## Test plan

1. Tag and parent chain: is `v2.6.4` annotated; does it point at the release commit; is that commit's only parent
   `a94b76a`; is `a94b76a`'s second parent `596fe97`, and is the merged tree identical to `596fe97`'s.
2. The release commit's diff: only release files.
3. `v2.6.3...v2.6.4`: exactly #73's files, its two QA docs (byte-identical to the originals) and the release files.
4. Main CI on `a94b76a` and the Release run.
5. Release body against the CHANGELOG 2.6.4 section, the upgrade note, compare links, version.
6. CHANGELOG: `[2.6.3]` and every older section, and the older link references, byte-identical to the `v2.6.3` tag's.

## Results

### 1. Tag and parent chain

- Tag `v2.6.4` is an **annotated** tag, tagger github-actions[bot], message `v2.6.4`, 14:13:36 EEST, unsigned like
  the earlier tags.
- It points at `205ed23ad1c4759b880af9b2c0bf84935250c3c1`, `chore(release): v2.6.4 [skip ci]` by
  github-actions[bot], whose **only parent is `a94b76a`**. `205ed23` was the head of `main` when checked.
- `a94b76a` ("Merge pull request #73 from thordin9/sec/hardening-followup", 14:03:09 EEST) has parents `1dbe986`
  (the v2.6.3 release commit) and **`596fe97`**, the head I hash-checked.
- `git diff 596fe97 a94b76a` is **empty**: the merged tree is exactly #73's reviewed head. `596fe97` is an ancestor
  of the tagged commit.
- #73's main merge `3dbcbc2` was already checked in the #73 hash check (no code conflicts; the code lines match what
  I passed at `959d0e1`).

### 2. Release commit

`205ed23` changes exactly two files:

- `VERSION`: `2.6.3` to `2.6.4`.
- `CHANGELOG.md` (+11 / −2): the `## [2.6.4] - 2026-10-24` header above the folded Unreleased text, six generated
  `### Maintenance` lines, the new `[2.6.4]` compare link and the moved `[Unreleased]` link.

`README.md` is not touched by the release commit (I-3).

### 3. The v2.6.3...v2.6.4 diff

- 13 commits ahead, 0 behind.
- 13 files changed (+709 / −15): `docker/nginx.conf`, `docs/ci.md`, `docs/configuration.md`,
  `docs/deployment-and-operations.md`, `scripts/docker-auth-checks.sh`, `scripts/imagick-policy-check.php`,
  `tests/Unit/ReleaseTest.php`, `tests/integration_hardening.php`, `README.md` (the `ALLOWED_HOSTS` row, #73), the two
  QA docs, `VERSION` and `CHANGELOG.md`. These are #73's files and the release files. Nothing else: no migration,
  `src/`, `public/`, Dockerfile or workflow change.
- QA docs at the tag, `cmp`-identical to `/workspace/qa-docs`:
  - `docs/qa/apotheke-v2.6.2-release-qa.md`: 28,129 B, sha256 `87256f4f…e965`.
  - `docs/qa/apotheke-pr73-main-hardening-followups-qa.md`: 16,444 B, sha256 `b5251cdb…174f`.
- The v2.6.3 release QA doc is not in main; the PM moved it into #75 on purpose. Not a finding.

### 4. Main CI and the Release run

CI run **40088337595** on `a94b76a` (push to main, attempt 1): **success**, 14:03:12 to 14:13:24 EEST.

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

The only skipped steps are the failure-only report uploads and the pgsql job's coverage steps, as for v2.6.3.

Release run **40089030181** (workflow_run, head `a94b76a`, attempt 1): **success**, 14:13:26 to 14:13:40 EEST. No
run exists for `205ed23` (`[skip ci]`), as expected.

Release id 422683534: published 14:13:38 EEST, not a draft or a prerelease, name `v2.6.4`, author
github-actions[bot], target `main`, no assets. `updated_at` equals `published_at`, so it has not been hand-edited.

### 5. Release body

- It **leads with #73's upgrade note**: health checks (`/healthz`, `/health`) by name need that name in
  `ALLOWED_HOSTS` or `PUBLIC_URL`; `localhost` and IP probes need no change.
- `### Fixed` has #73's one handwritten bullet (SEC-M3, #70 N-3: restore upload with an unknown host gets 421 from
  nginx, not 500), linked to #73.
- `### Maintenance` has six generated lines from #73's commit subjects, then #73's two handwritten bullets (S-M1
  ImageMagick policy check; the deploy docs for #70 L-2 and N-3 to N-7 plus the N-1 test). All link #73.
- No #71 or #70 bullet was carried over.
- The body up to `**Full changelog:**` is **identical** to the CHANGELOG 2.6.4 section, after trimming whitespace.
- **Links and versions:** the body ends with `**Full changelog:** …/compare/v2.6.3...v2.6.4`. The CHANGELOG adds
  `[2.6.4]: …/compare/v2.6.3...v2.6.4` and moves `[Unreleased]` to `…/compare/v2.6.4...HEAD`. `## [Unreleased]` is
  empty. `VERSION` at the tag is `2.6.4`.

### 6. CHANGELOG history

Comparing the v2.6.4 and v2.6.3 tags' `CHANGELOG.md`:

- The preamble up to `## [Unreleased]` is byte-identical.
- Everything from `## [2.6.3]` to the link references (32,697 B) is **byte-identical**.
- Link references: v2.6.4 has 28 and v2.6.3 had 27. Apart from the changed `[Unreleased]` and the new `[2.6.4]`, all
  are byte-identical.

## Security

- The released tree is byte-identical to #73's reviewed and hash-checked head plus the two release files (§1, §2).
- The release notes describe the hardening only, with no reproduction steps.
- The release commit adds no secrets, tokens or hosts. CI's Secret scan is green on `a94b76a`.
- This doc contains no secrets, tokens or private hostnames.

## Findings

### High

None.

### Medium

None.

### Low

None.

### Nit

- **N-1: the upgrade note says "follow the same rule" with no rule before it.** In the v2.6.4 body it is the first
  line, so "the same rule" (the Host allow-list from #70/v2.6.2) has no antecedent. A reader of v2.6.4 alone may not
  know what rule is meant. Rewording such as "Health checks are subject to the Host allow-list (since v2.6.2)" would
  read better next time. The released `[2.6.4]` section should stay as it is; a hand edit of the release body is
  optional.
- **N-2: "or `PUBLIC_URL`" is only true on a new install.** As v2.6.2 QA L-1 found, on an existing install the
  address saved under Control Panel, General takes precedence over `PUBLIC_URL`. The hand-edited v2.6.2 body and the
  #73 docs say this; the v2.6.4 note is shorter. Cosmetic; the same optional edit could add "(or the Control Panel
  address)".

### Info

- **I-1: reduced scope.** This is a diff-read QA under the compute rule: no image rebuild, HTTP smoke test, suite,
  PostgreSQL run, mutation or upgrade run. My #73 review covers runtime behaviour, and §1 shows the released tree adds
  only the release files to it.
- **I-2: no migration.** No `migrations/` file or schema code changed, so an upgrade needs no database step.
- **I-3: no README change in the release commit.** Unlike v2.6.3, the release commit leaves the README coverage table
  alone. #73 changes no PHP under coverage, so the figures are unchanged; the README diff in the release range is #73's
  own `ALLOWED_HOSTS` row.
- **I-4: Maintenance ordering and noise.** The six generated lines sit above #73's two handwritten Maintenance
  bullets and include CHANGELOG housekeeping ("record the #70 follow-up hardening"). Same by-design behaviour as
  v2.6.3 N-1.
- **I-5: no published image.** As before, the release ships only GitHub's source archives; CI's Docker build and
  smoke test is green on `a94b76a`.
