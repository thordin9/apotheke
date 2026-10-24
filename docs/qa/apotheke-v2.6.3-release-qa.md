# v2.6.3 release QA

Release: [v2.6.3](https://github.com/thordin9/apotheke/releases/tag/v2.6.3), cut automatically from main after PR
[#71](https://github.com/thordin9/apotheke/pull/71) (`fix(auth): harden sign-in, import, paging, and the database
line`, SEC-M1, SEC-L1, SEC-M2, SEC-N2, SEC-N3) merged as `a966ba5153343a1c5fdb780d9f07514b25faad4d`, pinned to
`e222835`. Previous release: v2.6.2.

**Verdict: PASS.** No High, Medium or Low findings. Two Nits and five Info notes.

## Scope

Under the project's compute rule, this is a **diff-read release QA**. v2.6.3 is one release commit (`VERSION`,
`CHANGELOG.md`, `README.md`) on top of #71, and QA2 fully reviewed #71 (suites on PHP 8.3 and 8.4 with SQLite and
PostgreSQL 17, the audit repros, 11 mutations; `docs/qa/apotheke-pr71-main-security-hardening-qa.md`). So I did
**not** rebuild the image, run nginx or php-fpm, run the test suites, use PostgreSQL, run mutations or test an
upgrade. The runtime results are QA2's at `f59b585`, which §1 ties to the released tree.

Sources: unauthenticated `api.github.com` and `raw.githubusercontent.com`. Read-only; nothing was posted. Times are
Athens time (EEST, UTC+3).

## Test plan

1. Tag and parent chain: is `v2.6.3` annotated; does it point at the release commit; is that commit's only parent
   `a966ba5`; is `a966ba5`'s second parent `e222835`, and does `e222835` add only the QA doc on top of QA2's reviewed
   head `f59b585`.
2. The release commit's diff: only `VERSION`, `CHANGELOG.md` and `README.md`; `v2.6.2...v2.6.3` touches only #71's
   files and the release files.
3. Main CI on `a966ba5` and the Release run.
4. Release body: exactly #71's five handwritten bullets, linked; compare links; version numbers; body against the
   CHANGELOG 2.6.3 section.
5. CHANGELOG: `[2.6.2]` and every older section, and the older link references, byte-identical to the `v2.6.2`
   tag's.
6. DevOps's hand-edited v2.6.2 release body: v2.6.2 QA's L-1, N-1 and N-2.

## Results

### 1. Tag and parent chain

- Tag `v2.6.3` is an **annotated** tag: tag object `b8a66bd965ae560a3744975821e5a8504fbed434`, tagger
  github-actions[bot], message `v2.6.3`, 13:08:07 EEST, unsigned like the earlier tags.
- It points at `1dbe9866da28b3e041d562782080889059f7c41f`, `chore(release): v2.6.3 [skip ci]` by
  github-actions[bot], whose **only parent is `a966ba5`**. `1dbe986` was still the head of `main` when checked.
- `a966ba5` ("Merge pull request #71 from thordin9/cursor/harden-review-findings-4ebc", 12:58:56 EEST) has parents
  `6251870` (the v2.6.2 release commit) and **`e222835`**. The PR API reports #71 merged at 12:58:57 EEST with merge
  commit `a966ba5` and head `e222835`.
- `e222835` (`docs(qa): add the #71 security hardening QA report`) has the single parent `f59b585`, QA2's reviewed
  head. `f59b585...a966ba5` is 2 ahead, 0 behind, and the only file changed is
  `docs/qa/apotheke-pr71-main-security-hardening-qa.md`. That file at `e222835` is `cmp`-identical to QA2's original
  (21,879 B).
- The `v2.6.2` tag is unchanged: tag object `9e0b6f0b`, commit `6251870`.

### 2. Release commit and the v2.6.2...v2.6.3 diff

- `1dbe986` changes exactly three files:
  - `VERSION`: `2.6.2` to `2.6.3`.
  - `CHANGELOG.md` (+13 / −1): the `## [2.6.3] - 2026-10-24` header above the folded Unreleased text, the generated
    `### Maintenance` list, and the compare links.
  - `README.md` (5 / 5): the coverage table. "Items", "Users & roles", "Control Panel settings", "Bootstrap, config,
    QR, seed" and "All PHP" changed (I-4).
- `v2.6.2...v2.6.3`: 12 commits ahead, 0 behind, merge base `6251870`. #71's 9 branch commits (`ca3fd4d` to
  `f59b585`), `e222835`, the merge `a966ba5`, then `1dbe986`.
- 22 files changed. They are #71's runtime, test and doc files as listed in QA2's review (`src/{config,users,
  settings,items}.php`, `public/index.php`, seven `tests/Unit/*Test.php`, `tests/integration.php`,
  `config.example.php`, four docs), its QA doc, and the three release files. Nothing else. No migration, Dockerfile,
  `docker/` or `scripts/` file changed.

### 3. Main CI and the Release run

CI run **40084086879** on `a966ba5` (push to main, attempt 1): **success**, 12:59:00 to 13:07:57 EEST.

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

The only skipped steps are the failure-only report uploads and the pgsql job's coverage steps, as for v2.6.2.

Release run **40084692372** (workflow_run, head `a966ba5`, attempt 1): **success**, 13:07:59 to 13:08:12 EEST. One
job, "Version, changelog, tag and GitHub Release", every step green. No run exists for `1dbe986` (`[skip ci]`), as
expected.

Release id 422653728: published 13:08:09 EEST, not a draft or a prerelease, name `v2.6.3`, author
github-actions[bot], target `main`, no assets. `updated_at` equals `published_at`, so it has not been hand-edited. A
patch bump fits a hardening release.

### 4. Release body

- `### Fixed` has **exactly #71's five handwritten bullets**: SEC-M1, SEC-L1, SEC-M2, SEC-N2 and SEC-N3, in that
  order, each linking [#71](https://github.com/thordin9/apotheke/pull/71). The wording is hardening-only, with no
  repro text. The SEC-M2 bullet matches the code QA2 verified: XFF is read only with Trust the reverse proxy on and a
  listed peer, and the client is the rightmost hop that isn't a listed proxy.
- `### Maintenance` has six generated lines from #71's `docs(...)` commit subjects, each linking #71 (N-1).
- No #70 or #62 bullet and no upgrade note were carried over. F-1 from QA2's review did not come back.
- The body up to `**Full changelog:**` is **identical** to the CHANGELOG 2.6.3 section, after trimming whitespace.
- **Links and versions:** the body ends with `**Full changelog:** …/compare/v2.6.2...v2.6.3`. The CHANGELOG adds
  `[2.6.3]: …/compare/v2.6.2...v2.6.3` and moves `[Unreleased]` to `…/compare/v2.6.3...HEAD`. `## [Unreleased]` is
  empty. `VERSION` at the tag is `2.6.3`.

### 5. CHANGELOG history

- Comparing the v2.6.3 and v2.6.2 tags' `CHANGELOG.md` (raw):
  - The preamble up to `## [Unreleased]` is byte-identical.
  - Everything from `## [2.6.2]` to the link references (30,186 B) is **byte-identical**.
  - The link references: v2.6.3 has 26 and v2.6.2 had 25. Apart from the changed `[Unreleased]` and the new
    `[2.6.3]`, all of them (`[2.6.2]` back to the first release) are byte-identical.

### 6. Hand-edited v2.6.2 release body

Release 422629243, `updated_at` 12:58:47 EEST, which is before the pause.

- **L-1: fixed.** "On a new install, setting `PUBLIC_URL` to that address works too; on an existing install, set the
  address under Control Panel, General instead, because the address saved there takes precedence." The health-check
  paragraph now says "… needs that name in `ALLOWED_HOSTS` (or as the public address, set as above)". Both are
  correct for the behaviour shown in v2.6.2 QA §7.
- **N-1: fixed.** The `### Maintenance` list has no blank line inside it, so it renders as one list.
- **N-2: fixed.** #62's change appears once, as the `**changelog:**` bullet. The generated `autofill:` and `release:`
  duplicates are gone. #62's two `qa:` QA-doc bullets stay, which is correct because they are separate changes.
- Nothing else changed: the upgrade note, the `ALLOWED_HOSTS` example, the Host names link, the three Fixed bullets,
  the other Maintenance bullets and the `v2.6.1...v2.6.2` link are as before. The body ends with three blank lines
  (N-2 here).
- The v2.6.2 body now differs from the CHANGELOG `[2.6.2]` section by these edits (I-3).

## Security

- The release adds no code beyond #71, and the released tree's runtime files are those QA2 passed at `f59b585` (§1,
  §2).
- The release notes describe the hardening only, by SEC id, with no reproduction steps.
- The added lines in the release commit contain no secrets, tokens or hosts. CI's Secret scan job is green on
  `a966ba5`.
- This doc contains no secrets, tokens or private hostnames.

## Findings

### High

None.

### Medium

None.

### Low

None.

### Nit

- **N-1: the v2.6.3 Maintenance list is commit noise.** It has six generated `docs(...)` lines from #71's branch,
  including two pieces of CHANGELOG housekeeping: "record the sign-in hardening" and "keep the #71 notes out of
  v2.6.2". The second reads oddly in v2.6.3's notes. This is by design: `rel_drop_scoped_generated()` only drops
  generated lines in the same section as a handwritten bullet with the same scope, and all five handwritten bullets
  are under Fixed. Next time, a handwritten Maintenance bullet, or a squashed docs history, would keep it short.
  Cosmetic.
- **N-2: the hand-edited v2.6.2 body ends with three blank lines** (`…v2.6.1...v2.6.2\n\n\n\n`). They don't
  render. Cosmetic; no action is needed.

### Info

- **I-1: reduced scope.** This is a diff-read QA under the compute rule. There was no image rebuild, HTTP smoke test,
  suite, PostgreSQL run, mutation or upgrade run. QA2's #71 review covers runtime behaviour, and §1 and §2 show the
  released tree adds only the QA doc and the release files to it.
- **I-2: no migration.** No `migrations/` file or schema code changed in `v2.6.2...v2.6.3`, so an upgrade needs no
  database step. The new `trusted_proxies` / `TRUSTED_PROXIES` setting is optional, and with it empty the connecting
  address is used. Not exercised here (I-1).
- **I-3: v2.6.2 body vs CHANGELOG.** The CHANGELOG `[2.6.2]` section still has the original L-1 wording, the N-1
  blank line and the N-2 duplicates. That is required, because `[2.6.2]` stays byte-identical to the tag. DevOps's
  follow-up carries the L-1 wording into the Host names doc. It should not edit the released `[2.6.2]` section.
- **I-4: README coverage.** "All PHP" moved from 64.5 / 91.6 / 96.1 % (6,891 lines) to 65.1 / 91.3 / 96.1 % (6,987
  lines). "Users & roles" moved from 91.3 / 93.8 / 96.4 % to 94.1 / 94.9 / 97.0 %.
- **I-5: no published image.** As for v2.6.2, the release ships only GitHub's source archives, and CI builds
  `apotheke:ci` without pushing it. CI's Docker build and smoke test is green on `a966ba5`.
