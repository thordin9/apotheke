# Apotheke PR #129: CI4 sync v2.6.21 QA

| | |
|---|---|
| PR | https://github.com/thordin9/apotheke/pull/129 |
| Verified head | `6f3c5c20cc57f614c3261af5f70d2a030132744a` (re-checked 27 Oct 00:05 EET, unchanged) |
| Base | `feature/ci4` `16c54b7` (unchanged; #128 @ `99c11af` approved, **not merged**, so no re-merge needed yet) |
| Merges | `c12e4c7` ← `d0a3916` + main `c6b6ff7` (v2.6.21); `6f3c5c2` ← `46c5c7c` + `16c54b7` (#127) |
| Delta | `e117b89` (config + test env), `46c5c7c` (docs) |
| Scope | Merge-only read + delta review; PHP 8.4 CI4 on SQLite + PG17 |
| Author CI | run 40399087498, 22/22 success at `6f3c5c2` (read-only gh) |

## Checklist

| Check | Result |
|---|---|
| `c12e4c7` remerge-diff | Pass: conflicts in **CHANGELOG + tests/lib.php only** |
| `6f3c5c2` remerge-diff | Pass: conflict in **HANDOFF only** |
| Main side, byte for byte | Pass: all 19 files of main's `935f144..c6b6ff7` patch land hunk for hunk, apart from the 2 conflicts; main touches no `ci4/` file |
| CHANGELOG `[2.6.21]` and older | Pass: identical to main (37,061 B); Unreleased on top |
| tests/lib.php resolution | Pass: `SIGN_IN_FAIL_MS=400` in `test_server_env`; CI4 Server keeps harness, overrides and `null` drops |
| `6f3c5c2` #127 side (diff-of-diffs, no HANDOFF/CHANGELOG/docs/qa) | Pass: equals `d0a3916..16c54b7` |
| `comm -12` both-parent | c12e4c7: CHANGELOG, README, bin/migrate.php, docs/configuration.md, HardeningHttpTest, lib.php (auto-merged except the 2); 6f3c5c2: CHANGELOG, HANDOFF |
| SignInPace (#124) intact | Pass: SignInPace, Accounts, SecurityController, Config\SignIn byte-identical to `7dd5168` |
| e117b89 parsing vs legacy | Pass: 26 env/file inputs, 0 mismatches (`'abc'`→400 as legacy, N-362) |
| N-311 migrate test, CI4 legs | Pass on SQLite + PG; control without `APOTHEKE_APP=legacy` fails on both |
| Lint / gitleaks `16c54b7..6f3c5c2` / toml sha | Pass / clean / `b5bc9f98…993a8f` |
| CI4 suites @ `6f3c5c2` | sqlite rerun **794 OK** (6 skipped); pgsql 794, 1 failure (N-403), 5 skipped |
| Config mutants (targeted) | 1/4 killed (N-401) |

## Security

**e117b89:** `ApothekeConfig` maps `SIGN_IN_FAIL_MS` and clamps with `min(10000, max(400, (int)(… ?? 1000)))`, the same expression as legacy `config_load`. The default is 1000. Cases tested: null, '', 0, 1, 399, 400, 1500, 10000, 10001, 99999, abc, 12abc, ' 700', -5, 400.9, 1e3, 0x10, +800, tab-900, and the config-file values int, string, float, null, true, array and -1. All give the same result on both. There is no accessor, and nothing in CI4 reads the value.

**Parity gap, checked at runtime** (CI4 SQLite, wrong sign-in, median of 5):

| Env | legacy | CI4 |
|---|---:|---:|
| `SIGN_IN_FAIL_MS=1000` | 1001 ms | 1006 ms |
| `SIGN_IN_FAIL_MS=2500` | 2501 ms | **1006 ms** |
| + `signin.failDeadlineMs=2500` | 2501 ms | 2506 ms |

**Tick race sanity** (real HTTP, 30 rounds, tick against finish): CI4 at `6f3c5c2` still races (SQLite 2/30, PG 17/30, 0 5xx). Legacy at the same head races 0/30 on both. CI4 Stocktake code is identical to `7dd5168`, so this is not a regression; #128 fixes L-340 on CI4.

**Cloudflare phase:** the delta adds no static, no superglobal and no new `getenv`; the value comes through `load($env)`. Already present: `fromEnvironment()` uses `getenv()`, and `Config\SignIn` reads `signin.failDeadlineMs` from env. These are not blockers for this PR; step 15 should pick one source.

## Findings (IDs from 400)

| ID | Sev | Note |
|---|---|---|
| L-400 | L | An operator who sets `SIGN_IN_FAIL_MS` gets it on legacy but not on CI4 (2500 → 1006 ms). Only `signin.failDeadlineMs` works there. The defaults match, so default installs are fine. The deadline is shorter than set only when the setting is raised, for example for slow hashes. Tracked in HANDOFF for step 15. **Document now:** the CHANGELOG Unreleased #129 line ("loads `sign_in_fail_ms` as legacy does") reads as if CI4 honours it. Add "CI4 does not use it yet; set `signin.failDeadlineMs`" to that line and to the `sign_in_fail_ms` row in docs/configuration.md. Also note there that CI4 starts the deadline at the controller, not at the request start. |
| N-401 | N | `ApothekeConfigTest` covers only the default. Dropping the clamp, dropping the env map entry, or using a minimum of 1 all survive; changing the default to 999 is killed. Add ConfigTest's 1500/0/99999 cases (and abc) on the CI4 loader. |
| N-402 | N | CI4 tick-vs-finish race (L-340) is still present at this head while legacy is fixed (v2.6.21). Pre-existing, fixed in #128. If #128 merges first, #129 needs a plain re-merge and a merge-only read. |
| N-403 | N | `HardeningHttpTest::testFailedPasswordChecksAnswerAtTheDeadline` got status 0 (no answer within the client's 30 s) on the turn-on `"\0ada"` request. This happened once on each DB, during full runs while the box was busy (my probes plus other gates). It passed alone 3/3, as a class 2/2 on SQLite and 1/1 on PG, and in the SQLite full rerun; CI is green. Treated as a flake under load; watch it. |

No H/M.

## Verdict

**PASS @ `6f3c5c2`.** Both merges are clean: conflicts only in CHANGELOG, lib.php and HANDOFF, and main and #127 land byte for byte. Older CHANGELOG sections match main exactly. `e117b89` parses exactly like legacy, has no app behaviour change, and adds no Cloudflare blocker. SignInPace is intact. L-400 is a known, tracked parity gap; the docs should be clarified now, and this does not block the merge. N-401 to N-403 are optional notes.
