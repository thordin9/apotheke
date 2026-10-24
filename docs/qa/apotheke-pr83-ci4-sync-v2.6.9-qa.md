# QA: apotheke #83, sync main v2.6.9 into feature/ci4

- **PR:** [#83](https://github.com/thordin9/apotheke/pull/83), `chore(ci4): sync main v2.6.9 into feature/ci4`, into
  `feature/ci4`, by DevOps
- **Head pinned:** `7c13a88ac3f67549a40ad6815bfceba4fa8a191f`
- **Base:** `feature/ci4` at `f888dc0`. Merge base with main: `e2628f2` (v2.6.1), so the sync brings v2.6.2 to v2.6.9.
- **Commits reviewed:** `3fd598f` (merge of main `d821bb8`, v2.6.8), `090a7d0` (link check), `7911f07` (bullet),
  `c7c5d1c` (merge of main `3f729a3`, v2.6.9), `9491f98` (CI4 schema copy of 0027), `7c13a88` (bullet and counts)
- **Scope:** what the sync changes on `feature/ci4`. Main's features (#70 to #80) were reviewed on main and are not
  re-reviewed here.
- **Verdict: PASS.** 0 High, 0 Medium, 1 Low, 4 Nits, 8 Info.

## 1. Test plan

1. Both merges replayed with `git merge-tree` and compared with the merge commits. Each conflicted file read against
   both parents, line by line and word by word.
2. Every file main changed since the merge base, checked against the head: main-only files byte for byte, files
   changed on both sides against a clean three-way merge, conflicted files by hand. Every ci4-only file checked
   unchanged.
3. CHANGELOG gate against main `3f729a3`.
4. `090a7d0`: link-check mutants on `docs/` and `docs/qa/`, and the skip removed.
5. `9491f98`: the CI4 schema rows compared with main's `src/settings.php` and migration 0027. Eight schema mutants.
   CLI checks on SQLite and PostgreSQL 17 for legacy-to-CI4 upgrades, with and without the key, plus a rollback to the
   pre-sync CI4 code.
6. PHPUnit: legacy and ci4 on PHP 8.4 and legacy on PHP 8.3, each on SQLite and PostgreSQL 17. deptrac, DI rule,
   `scripts/lint.sh`, `check-commits.sh`.
7. CI4 Cloudflare-phase review and security review of what ci4 now carries in docs but does not implement.
8. CI and the PR body through the GitHub API.

**Not run here:** Docker, so the Docker, HEIC and Playwright jobs ran in CI only (§2.1).

## 2. Results

### 2.1 CI

| Head | Run | Result |
|---|---|---|
| `7c13a88` (pinned) | 40101213000 (`pull_request`, attempt 1) | **`completed` / `success`, 22/22 jobs**, 17:09:04 to 17:21:02 (Athens, UTC+3) |

The jobs were Lint (8.3, 8.4), deptrac, DI rule, Tests (legacy@8.3 and legacy@8.4 on sqlite and pgsql, ci4@8.4 on
sqlite and pgsql), Headless mobile (8.3, 8.4), Visual E2E (3 shards on each of 8.3 and 8.4), Docker build + smoke
test (8.3, 8.4), Conventional Commits and Secret scan. From the logs:

- **Docker (PHP 8.3):** main's new steps run inside ci4's per-PHP matrix. These are the ImageMagick policy check
  (`delegate *` and `path @*` have rights None), `docker-signin-checks.sh` (all cases, including "sign-in turned off in
  the database: refuses again"), `docker-checks.sh` (404 sets no session cookie) and `docker-auth-checks.sh` (30 MB
  restore refused before sign-in and accepted after it).
- **Tests (ci4@8.4, pgsql):** 555 tests, 13,160 assertions, 3 skipped.

### 2.2 Merges

**Replay.** `git merge-tree` of each merge's parents gives exactly the conflicted files DevOps listed. These were 8 in
`3fd598f` (CHANGELOG, README, `docs/ci.md`, `docs/control-panel.md`, `docs/deployment-and-operations.md`,
`docs/user-guide.md`, `scripts/lint.sh`, `tests/Unit/DockerfileTest.php`) and 2 in `c7c5d1c` (CHANGELOG,
`docs/user-guide.md`). Every other file in both merge commits is identical to the automatic merge.

**Main's delta (merge base `e2628f2` to `3f729a3`, 85 files), checked at `c7c5d1c` and at the head:**

| Class | Files | Result |
|---|---|---|
| Changed only on main | 65 | **Byte-identical to `3f729a3`**, including all 17 new `docs/qa/` docs |
| Changed on both sides, no conflict | 12 (`ci.yml`, `AGENTS.md`, `Dockerfile`, `bin/settings.php`, `composer.json`, `config.example.php`, `docs/architecture.md`, `docs/autofill-admin-guide.md`, `docs/autofill.md`, `docs/configuration.md`, `docs/getting-started.md`, `docs/releasing.md`) | **Equal to a clean three-way merge** (`git merge-file`) of `f888dc0` and `3f729a3` |
| Conflicted | 8 | Read by hand (below) |
| Changed only on ci4 | 268 unchanged, plus 2 changed by `090a7d0` (`scripts/check-links.mjs`, `LinkCheckTest.php`) | As intended |

Total against the base: 89 files, +7,727/−196. After `c7c5d1c`, only `CHANGELOG.md`, `SettingsSchema.php` and
`Ci4MigrateTest.php` change. **No divergence from main's delta was found.**

**Conflict resolutions.** I checked every line that either side added since the merge base. Each one is present in
the head, or merged into a combined line that keeps both sides' text. No line that main removed is still present.

| File | Resolution | Result |
|---|---|---|
| `README.md` (CI table) | ci4's rows. The Docker row adds main's policy check, Host check and `docker-auth-checks.sh` to ci4's PHP 8.3/8.4 text | Complete, no duplicates |
| `README.md` (layout) | ci4's `ARG PHP_VERSION` Dockerfile line and main's `127.0.0.1:9091` compose line | Correct |
| `docs/ci.md` | Word diff: head minus ci4's Docker row is exactly main's base-to-`3f729a3` Docker-row delta. The Lint row adds `release.php check-links` (main changed `lint.sh`, but not this doc row) | Complete, and it matches the auto-merged `ci.yml` |
| `docs/control-panel.md` | Main's `TRUSTED_PROXIES` and DSN display, plus ci4's database-failure sentence | Complete |
| `docs/deployment-and-operations.md` | Both upgrade bullets: ci4's `;` in credentials and main's Host names | Complete |
| `docs/user-guide.md` (both merges) | ci4's database-failure sentence plus main's import/restore paragraph, now with "the idle limit"; reflowed | Complete and coherent |
| `scripts/lint.sh` | ci4's actionlint block, then main's `changelog links` step | Both run (lint output §2.5) |
| `tests/Unit/DockerfileTest.php` | Main's two new tests (compose localhost bind, entrypoint refuses with sign-in off). ci4's `dockerfile()`/`pin()` replace main's `pinOk()`, which ci4 had already replaced with the ARG-based pin | Correct. All 6 methods are used, and no `pinOk` is left |
| `CHANGELOG.md` (both merges) | ci4's `[Unreleased]`, then main's released sections | See §2.3. Also has two non-resolution edits (N-3) |

### 2.3 CHANGELOG gate

| Check | Result |
|---|---|
| `## [2.6.9]` to EOF (602 lines, 43,161 bytes) | **sha256 `7d6c50cf362517e0b00af5b12fadc2c02b782d87d381a451da77cdf8376653a9`**, the same as `3f729a3` and `origin/main` |
| Header above `[Unreleased]` | Identical to main |
| ci4's `[Unreleased]` against `f888dc0` | Intact. The only changes are the #53 and #64 counts (26 → 27 migrations, 63 → 64 keys, both now true), the two link-format edits from `3fd598f` (N-3), and one new bullet |
| #83 bullet | Under `### Maintenance`, linked `([#83](…/pull/83))`. It replaces the v2.6.8 sync wording, so there is no duplicate, and it says "CI4 does not enforce the idle limit yet" |
| `release.php check-links` | Passes (lint) |

**Gate: PASS.**

### 2.4 Link check (`090a7d0`)

The new line skips any non-http link target that starts with `…`. It runs on `docs/` and `docs/qa/`, with the 84 files
and 251 links at the head.

| Mutant | Result |
|---|---|
| Base | exit 0, 0 broken |
| A: `[x](missing-qa.md)` added to a `docs/qa/` doc | **exit 1**, `missing file missing-qa.md` |
| B: `[x](nope.md#a)` added to `docs/ci.md` | **exit 1** |
| C: `[x](configuration.md#no-such-anchor)` in `docs/ci.md` | **exit 1**, `missing anchor` |
| D: `[x](…#host-names)` in a `docs/qa/` doc | exit 0 (intended) |
| E: `[x](…/does-not-exist.md)` in `docs/ci.md` | exit 0 (N-1) |
| F: `[x](…missing.md)` in `docs/ci.md` | exit 0 (N-1) |
| G: `[x](./…#a)` | **exit 1** |
| H: skip line removed | **exit 1** on `apotheke-pr73-main-hardening-followups-qa.md:165` (`…#host-names`). Both `LinkCheckTest` tests fail, and they pass with the line |

**No QA doc was modified.** Every `docs/qa/` file added by the sync is byte-identical to main, and no existing QA doc
changed.

### 2.5 `9491f98`: settings schema copy of 0027

**Rows.** `session_days` and `session_idle_hours` in `ci4/modules/Settings/SettingsSchema.php` are byte-identical to
main's `src/settings.php`: section `security`, label "Sign out after idle (hours)", type `int`, default `168`, bounds
`1`–`8760`, the help text, and the reworded `session_days` help. Migration 0027 itself comes from main unchanged, and
CI4 applies it through its PDO runner.

**CLI, CI4 against legacy v2.6.9 (SQLite and PostgreSQL 17):** `set session_idle_hours` with `0`, `-1`, `8761`,
`abc` and `1.5` gives the same refusal text from both apps ("between 1 and 8760" or "enter a whole number"). `1` and
`8760` are stored by both, and CI4's `export` includes the key.

**Database compatibility (SQLite and PostgreSQL 17, same results on both):**

| Case | Result |
|---|---|
| S1: legacy v2.6.8 DB (26 migrations, no key), then CI4 `migrate` | Applies `0027`, which gives 27 migrations, 64 rows and `168`. CI4 and legacy v2.6.9 `get` both give 168, and legacy `migrate` is "Already up to date" |
| S2: legacy v2.6.9 DB with the key set to 24, then CI4 | "Already up to date". CI4 `get` is 24, and values set by CI4 are read by legacy |
| S3: v2.6.9 DB with the row deleted (0027 recorded) | CI4 and legacy both give the schema default of 168. Nothing is re-inserted |
| S4: v2.6.8 DB with the key already present (48), 0027 not recorded | CI4 `migrate` records 0027 and keeps 48 |
| S5: fresh CI4 DB, then legacy v2.6.9 | "Already up to date", value 168. `Ci4MigrateTest` also checks that the full dump matches legacy |
| S5 rollback: pre-sync CI4 (`f888dc0`) on that DB | "Already up to date". `get session_idle_hours` gives "Unknown setting", `export` has 63 keys and `list` exits 0 (I-6) |

**Mutants** (`Ci4MigrateTest`, `Ci4SettingsSchemaTest`, `Ci4SettingsCliTest`, `SettingsTest`, with 34 tests and
8,422 assertions on the base):

| # | Mutation | Result |
|---|---|---|
| M1 | `session_idle_hours` row removed | **killed** (9 failures) |
| M2 | Default 168 → 167 | **killed** (11) |
| M3 | Max 8760 → 8761 | **killed** (3) |
| M4 | Min 1 → 0 | **killed** (3) |
| M5 | Help text changed by one phrase | **killed** (2) |
| M6 | `session_days` help reverted to the pre-#80 text | **killed** (2) |
| M7 | Section `security` → `data` | **killed** (2) |
| M8 | Label changed | **killed** (3) |

The legacy-against-CI4 golden comparison of the whole schema catches any drift, and the count assertions catch a
missing row.

### 2.6 Test suites (PHP 8.4.27 and 8.3, PostgreSQL 17 with a throwaway role and databases)

| Leg | PHPUnit |
|---|---|
| legacy @ 8.4, SQLite | **OK**, 555 tests, 13,131 assertions, 6 skipped |
| legacy @ 8.4, PostgreSQL 17 | **OK**, 555 tests, 13,136 assertions, 5 skipped |
| ci4 @ 8.4, SQLite | **OK**, 555 tests, 13,129 assertions, 7 skipped |
| ci4 @ 8.4, PostgreSQL 17 | **OK**, 555 tests, 13,134 assertions, 6 skipped |
| legacy @ 8.3, SQLite | **OK**, 555 tests, 10,609 assertions, 41 skipped |
| legacy @ 8.3, PostgreSQL 17 | **OK**, 555 tests, 10,610 assertions, 41 skipped |

- **The HTTP suite** (`HttpSuiteTest`, which includes `tests/integration.php`) passes on the legacy legs. The ci4 legs
  skip it by design.
- **Skips are environmental.** On 8.3, the CI4 tests need PHP 8.4. Everywhere, the CI-only branches of
  `Ci4ConnectFailureLeakTest`, `Ci4HttpTest` and `ReleaseTest` skip, plus the known `php-cgi` and libheif skips.
- **Setup note.** The PostgreSQL tests create their own throwaway roles. The first two local runs had 15–16 errors,
  all "permission denied to create role" or "must be able to SET ROLE", until the test role had `CREATEROLE` and
  `createrole_self_grant`. That is an environment issue and was fixed before the final runs. Every role and database
  was dropped afterwards.
- **deptrac:** 0 violations, 0 errors. **DI rule:** OK. **`scripts/lint.sh`:** exit 0, covering PHP, JS, 251 links
  in 84 files, shellcheck, actionlint and changelog links. **`check-commits.sh f888dc0 7c13a88`:** 83 commits checked,
  ok.
- **#81's 8-character `APP_PASSWORD` minimum is not in this sync** (v2.6.9 only). There is no `app_password_problem()`
  in `src/`, and `tests/integration.php` still uses a 6-character fixture (I-5).

### 2.7 Commits

The 4 non-merge subjects are Conventional Commits of 44 to 63 characters: `ci(lint): …`, `docs(changelog): …` (twice)
and `feat(ci4): …`. The two merges, `Merge main d821bb8 into feature/ci4` and `Merge main 3f729a3 into feature/ci4`,
are plain merges like #61's, and `check-commits.sh` skips them. History is not rewritten. Both merges have main as the
second parent.

### 2.8 CI4 Cloudflare phase

- **CI4 code changed by the sync:** only `SettingsSchema.php`, with one added constant data row and one changed help
  string. No new static or global state, storage is untouched, and DB config stays DSN-driven. deptrac and the DI rule
  are green.
- **#79's `static $pending` / `static $hooked`** are in legacy `src/items.php` (lines 975 and 981) only. Nothing in
  `ci4/` takes them over yet. This is the known #79 I-5, listed in the PR body for Coder's port.
- The PR body's "No CI4 app code changes" predates `9491f98` (N-4).

## 3. Findings

| ID | Grade | Finding |
|---|---|---|
| L-1 | Low | **CI4 now carries session settings whose meaning it does not implement (security, docs/behaviour mismatch).** `9491f98` copies main's rows exactly, as it must for the golden test. So CI4 stores, validates, exports and imports `session_idle_hours`, and describes `session_days` as "The longest a sign-in lasts, counted from sign-in. Later visits do not extend it." But CI4's `SessionGate` still ends a session only after `session_days` without use, and stamps `last_seen` on every request. On a CI4 route, a session idle for 8 days (beyond legacy's 168 h default) or 2 h with `session_idle_hours=1` is still accepted, and a session in daily use never reaches `session_days` on the server (checked against `SessionGate::expired()`). The idle gap is disclosed in the #83 bullet and the PR body. The `session_days` wording is not. **Impact today is nil:** the image serves only the legacy front controller, and CI4's sign-in forms are still on legacy. Required before CI4 serves signed-in routes: Coder2's #80 port (absolute `session_days` plus `session_idle_hours`) with tests. Until then, add a short CI4 note next to `control-panel.md`'s existing CI4 paragraph: "CI4 does not enforce `session_idle_hours` yet; `session_days` is an idle limit there." Not blocking. |
| N-1 | Nit | **The link-check skip is broader than `(…#anchor)`.** `target.startsWith('…')` skips every target that begins with `…`, so `(…/does-not-exist.md)` and `(…missing.md)` pass (mutants E and F). No real link starts with `…`, so the risk is small. Suggest matching `^…(#[^)]*)?$` only, and adding a `…/x.md` case to `LinkCheckTest` as a still-checked target. The #83 bullet and PR body already describe it as "(…#anchor)". |
| N-2 | Nit | **`docs/autofill.md` (CI4 section) is now out of date.** It says "The header pre-check from #72 waits until that change is on `feature/ci4`". After this merge #72 is on `feature/ci4`, yet CI4's Autofill still has no header or decode pre-check or PNG cap (`AnalysisCopyBuilder` has no pixel limits). Reword it as the port follow-up in the PR body (Coder). The same paragraph's "Host and same-origin checks arrive with #76" predates this PR and is stale too. |
| N-3 | Nit | **The merge commit `3fd598f` changes text beyond the resolution.** It adds the #53 link to the CI4 DSN-spaces bullet (#67 N-1) and splits `([#66](…), [#68](…))` into two groups for main's `check-links`. This is declared in the PR body, the wording is unchanged, and it is correct, but edits inside a merge are easy to miss when auditing. Next time, put them in their own `docs(changelog):` commit. |
| N-4 | Nit | **Parts of the PR body are stale.** "Cloudflare phase: No CI4 app code changes" and the first-merge note "the CI4 schema copy and counts (26 migrations, 63 settings rows) stay as they are" were both overtaken by `9491f98` and `7c13a88`. Update the body so it matches the merged state. |
| I-1 | Info | **All claims verified.** These cover the conflict list and resolutions, main's delta arriving unchanged (65 files byte for byte, 12 equal to a clean three-way merge), the CHANGELOG hash, the `090a7d0` skip and its test, the copy of 0027 and its counts, #67 N-1 and I-1, CI 22/22, and the local suites. The exceptions are N-1 and N-4. |
| I-2 | Info | **Database compatibility holds both ways** (§2.5 S1–S5). Legacy 2.6.8 and 2.6.9 databases, with or without the key and with or without 0027 recorded, migrate and read consistently under CI4, and the reverse also holds, on SQLite and PostgreSQL 17. |
| I-3 | Info | **The PR body lists 4 CI4 port follow-ups**, recorded here. (1) nginx sends `/backup/restore` through `auth_request` to `/backup/restore/check`, which CI4 must serve (204/401/403, no session) before it serves backup routes, otherwise a large restore fails closed with 500. (2) The #72 and #79 image decode limits and PNG cap on CI4's Autofill suggest, plus #79 I-5 (static state to per-request), for Coder. (3) A CI4 equivalent of `settings_database_label()` for Settings → System. (4) `auth status` exit codes 0 and 3 must survive when the Auth module takes the `auth` forms. |
| I-4 | Info | **`AGENTS.md` is stale.** It still says "The 25 migrations stay byte-identical" and "Do not add a `0026+` migration on 3.0.x …". There are now 27 migrations, and 0026 and 0027 were added on 2.6.x. This was left out of this PR on purpose. |
| I-5 | Info | **The next sync (v2.6.10 with #81) brings the 8-character `APP_PASSWORD` minimum.** `tests/integration.php` (line 1122, `Password mode`) still uses a 6-character fixture, which will fail the same way #80's did on #81 (`8f58790`). Change it in that sync's merge follow-up. |
| I-6 | Info | **Rolling back to the pre-sync CI4 code is safe.** On a 27-migration database, `f888dc0`'s CI4 reports "Already up to date", treats `session_idle_hours` as unknown (`get` refuses, `export` has 63 keys) and otherwise works. |
| I-7 | Info | **Docs describe the legacy app.** README, `configuration.md`, `control-panel.md` and the user guide describe the shipped legacy behaviour, which is right because `main`/legacy stays the product until 3.0.0. Main's other security changes carried in docs are already in CI4 (Host allow-list 421, same-origin, sessions on demand, `TRUSTED_PROXIES`, admin-only import of the whole `security` section, which covers `session_idle_hours`), or are covered by I-3 (restore check, image limits) or L-1 (sessions). |
| I-8 | Info | **CI's Docker job now runs main's sign-in, auth and policy checks in both PHP cells on `feature/ci4`** (§2.1). Those checks run against the legacy front controller. |

## 4. Security

- **Merged hardening is intact.** Main's security fixes from 2.6.2 to 2.6.9 reach `feature/ci4` byte for byte, or as
  clean three-way merges, in `src/`, `bin/`, `docker/`, `Dockerfile`, `ci.yml` and the docs. The conflict resolutions
  dropped no security text: the Host-names upgrade note, the restore check, the policy check, docker-signin and
  docker-auth are all present. CI's Docker job ran them green.
- **CI4 gaps (docs or schema promise more than CI4 does):**
  - The session limits in L-1 (Low). This is the only new mismatch the sync introduces.
  - The #72 and #79 image limits on CI4's suggest route (I-3 and N-2), which were already open before this PR and
    are disclosed.
  - The SEC-L2 restore-check route (I-3), which only matters once CI4 serves backups.

  None of these is reachable in the shipped image, which runs only the legacy front controller. All of them must be
  closed before the 3.0.0 cutover.
- **#77 CLI admin and SEC-M3.** `bin/settings.php` keeps the `auth` and account forms on legacy even with
  `APOTHEKE_APP=ci4`, so the entrypoint's `auth status` and the CLI admin fix apply to both apps (I-3 item 4).
- **SEC-N6 on CI4** is still the older rule, an idle limit of `session_days` (L-1). It is weaker than main's #80, but
  not weaker than CI4 was before this PR.
- **Link check.** The skip can hide only targets that start with `…`, which never resolve (N-1). Security docs are
  not affected.
- **Secrets.** There are no secrets, tokens or real host names in the diff. CI's Secret scan is green. This review used
  a random throwaway PostgreSQL role and databases, which are not recorded here and were dropped afterwards.

## 5. Verdict

**PASS** for `7c13a88ac3f67549a40ad6815bfceba4fa8a191f`: 0 High, 0 Medium, 1 Low (L-1), 4 Nits, 8 Info.

- **CI:** run 40101213000, 22/22 `success` at the pinned head.
- **Merges:** 10 conflict resolutions (8 + 2) are complete and coherent. Main's delta arrives unchanged.
- **CHANGELOG gate:** passed. `[2.6.9]` to EOF hashes to `7d6c50cf…53a9`, the same as main. ci4's `[Unreleased]` is
  intact, and the #83 bullet is linked.
- **Tests:** 555 PHPUnit tests green on all 6 legs (legacy and ci4 @ 8.4, legacy @ 8.3, each on SQLite and PostgreSQL
  17). deptrac, DI rule, lint and check-commits are green. 8 of 8 schema mutants and the link-check mutants are killed,
  except the N-1 breadth.
- **Follow-up:** L-1 belongs to Coder2's #80 port. The Nits fit a later PR. None blocks the merge. This doc should be
  committed unchanged as the last `docs(qa):` commit on top of `7c13a88`.
