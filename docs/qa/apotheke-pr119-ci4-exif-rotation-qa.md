# QA: PR #119 (CI4, L-270: EXIF orientation without allow_url_fopen)

Head `b7dc7e07025cc9679a25b99916bb217e2a73d41d`, base feature/ci4 `4307fd1` (tip and merge-base). CI4 on PHP 8.4, SQLite and PostgreSQL 17.
Scope: the #119 delta only. `AnalysisCopyBuilder::exifFromBytes()` reads EXIF from a `php://memory` stream instead of a `data:` URI. Path-first EXIF is unchanged.

## Checklist

| Area | Result |
|---|---|
| Lint, deptrac (0 errors), DI check | PASS |
| gitleaks `4307fd1..head` (1 commit); `.gitleaks.toml` sha b5bc9f98…3a8f unchanged | PASS, no leaks |
| CI4 unit SQLite / PostgreSQL 17 | 457 / 583 OK (2 / 0 skipped) |
| CI4 HTTP suite SQLite / PostgreSQL 17 | 716 / 716 OK (39 / 6 skipped, environment) |
| CI 40301433429 | 22/22; the new step ran 1 test on both CI4 legs |
| L-270 at the base | reproduced |
| Byte parity with legacy, `allow_url_fopen` On and Off, both drivers | identical, 19 inputs |
| Robustness (huge or malformed EXIF) | PASS |
| Mutation, 9 mutants (3 equivalent) | PR checks 2/6; QA harness 6/6 |
| Photo requirement | holds |

## Verification

- **L-270 at the base.** With `allow_url_fopen=Off`, a `photo_id` suggest sends Orientation 6 as 1024x683, and orientations 2 to 8 all differ from legacy. At the head the same inputs send 683x1024 and match legacy byte for byte.
- **Parity.** A fake transport recorded every sent image for legacy, the head and the base. It ran with `allow_url_fopen` On and Off, on SQLite and PostgreSQL.
  - Inputs:
    - JPEG with orientations 1 to 8, plus a big-endian Orientation 6
    - no EXIF, and three corrupt EXIF blocks (bad offset, truncated, huge count)
    - Orientation 6 with GPS, and a near-64 KB EXIF
    - PNG, HEIC, 6000x4000 Orientation 6, and a real 1920x1440 photo
  - The head is byte-identical to legacy in all 4 combinations. Every sent copy is a JPEG of at most 1024 px with no APP1, EXIF or GPS. The original is byte-identical to the upload before and after the suggest, and the served copy is unchanged.
- **Robustness.** Probed directly under `allow_url_fopen=0`. Inputs: a maximum-size APP1, 200 APP1 segments (12 MB), looping IFD and EXIF pointers, a huge count, a bad byte order, a header-only block, garbage, 20 MB of trailing junk and non-JPEG bytes.
  - No exceptions, no output and no unsuppressed warnings. Suggests over HTTP return 200 with clean JSON, and nothing reaches the server log.
  - Extra memory is at most one copy of the input. Each call took under 0.2 s.
  - The stream is closed every time (0 stream resources leaked). No temp files are created.
- **The new test.** It fails under `allow_url_fopen=0` when `exifFromBytes()` is reverted to the `data:` URI, and passes with the fix.
- **ci.yml.** 3 lines added, none changed or removed. The step runs only on the CI4 legs, has no `continue-on-error`, and `--fail-on-empty-test-suite` / `--fail-on-skipped` make sure the one test really runs. No other step is affected.
- **Whole suite under `allow_url_fopen=0`.** Feasible. The full CI4 unit suite (457 tests, about 90 s locally) has only the 2 known errors. Both come from 3 test helpers that call the stub over `http://` with `file_get_contents` (N-281).
- **Cloudflare phase.** The memory stream is per call and closed in `finally`. There are no files, temp files, static state or superglobals.
- **Legacy `main` `data:` fallback.** Not graded. Legacy always passes a file path, so the fallback is reached only when that file has no readable EXIF, and then the bytes have none either. `autofill_prepare_image()` has no callers.

## Mutation

| Mutant | PR checks (suite + new CI step) | QA harness |
|---|---|---|
| `exifFromBytes` reverted to the `data:` URI | killed (CI step only) | killed (Off) |
| Bytes EXIF always false | killed | killed |
| Orientation 6 rotated the wrong way | survived | killed |
| Orientation 5 wrong | survived | killed |
| Orientation 8 treated as 6 | survived | killed |
| Orientation 2 flipped vertically | survived | killed |
| Path-first EXIF skipped | survived | equivalent (every caller's path holds the same bytes) |
| Stream not rewound | survived | equivalent (`exif_read_data` seeks to the start) |
| `fclose` dropped | survived | equivalent (the stream is freed on return) |

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-280 | N | Test gap: the new test checks only the dimensions, and compares against the same builder, so a wrong rotation direction passes. The PR suite misses all 4 orientation-map mutants. Hardening: assert a corner pixel or compare against fixed bytes for orientations 2 to 8. |
| N-281 | N | CI scope: only 1 test runs with `allow_url_fopen=0`. `clearStub()`, `suggestCount()` and `uploads()` in `AutofillTest` read the stub over `http://`. Under Off, `clearStub()` fails silently, because its errors are suppressed. Hardening: switch the helpers to curl, then run the whole CI4 unit suite under `allow_url_fopen=0`. |

No High, Medium or Low findings.

## Security

- **Privacy.** Only a resized copy with metadata stripped leaves the install, with `allow_url_fopen` On or Off. Originals are never changed.
- **Input handling.** Malformed or oversized EXIF produces no warnings in the output, no 500, and bounded memory and time. No stream or temp file is left behind.
- **Gates.** gitleaks is clean and the config is unchanged. There are no secrets in the diff, the CHANGELOG or the docs.

## Verdict

**PASS (0H/0M/0L/2N).** L-270 is fixed. N-280 and N-281 can go into a follow-up PR. Commit this doc byte-for-byte as the last commit.
