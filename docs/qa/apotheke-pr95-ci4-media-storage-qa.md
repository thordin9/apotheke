# QA: apotheke #95, CI4 Media module (PLAN step 10, media storage)

- **PR:** [#95](https://github.com/thordin9/apotheke/pull/95), `feat(ci4): media storage with main's layout and decode limits` (into `feature/ci4`). Full gate, then a delta re-check.
- **Head:** `9738b9f`. **Base:** `feature/ci4` `7ebb484`. 6 commits (`9ba448c`, `2e74cd3`, `dc4ed70`, `b6a3a32`, `119fbf3`, `9738b9f`). First gate: CHANGES REQUESTED at `2e74cd3`.
- **Verdict: PASS at `9738b9f1cb7578efda8f8beab64cc474e472ce43`.** 0 High, 0 Medium, 0 Low; Nits N-24 (partly), N-30 and N-31 open.

## Checklist

| Check | Result |
|---|---|
| CI | 22/22 at `9738b9f` (23/23 at `2e74cd3`), as reported by Coder. The GitHub API isn't reachable from here. |
| Decode limits | Every constant equals `main`'s `src/image-color.php`: 250 MP / 30,000 px, HEIC 128 MP / 16,384 px, GD 40 MP, PNG 26 MP, and the Imagick limits. Checked over a grid of 2,500+ edge sizes and types. The order matches `main`: header limits run before the original is written. |
| Edge uploads vs `main` | 19 crafted files, each also run through `main`'s `image_store()`. They include header-only and over-limit PNG, GIF, JPEG and WebP, truncated JPEG, animated GIF, fake and real HEIC, HEIC sizes patched to just under and just over 128 MP, and a real 40 MP JPEG. Every case gave the same error or byte-identical full and thumb images, and no refused upload left a file. |
| Served copy, originals | Byte-identity with `main` is tested by running `main`'s code, and mutants show the test isn't vacuous. The placeholder is identical to `main`. Originals are kept unchanged as `<64hex>.<ext>` plus `map/<32hex>`, files 0640. Only the stripped copy is ever produced. |
| `/media` | 28 paths × GET/HEAD against legacy. Same cache headers, ETag, nosniff and Content-Length; HEAD has no body; only an exact `If-None-Match` gets 304. Traversal, encoded, uppercase, wrong-extension and long names, originals and map entries all get 404 or are refused. |
| Cleanup | The undo list runs on an exception, a fatal error (memory or time limit, over HTTP and CLI) and the end of the request. After any of these, 0 files are left; with the handler call disabled, 3 are left. A finished upload is kept, as on `main`. The list is per request and can't run a step twice. |
| Worker / R2 phase | Media goes only through `Core\Storage`. The cleanup list is reset every request (not a persistent service). No static state, and no `register_shutdown_function`. |
| Tests, PHP 8.4 | CI4 module suite: SQLite 261 OK, PostgreSQL 17 261 OK. Full PHPUnit (`APOTHEKE_APP=ci4`): SQLite 610 OK (6 skipped), PostgreSQL 17 610 OK (5 skipped). Legacy (PHP 8.3, first gate): 610 OK (51 skipped). Legacy code is unchanged since. |
| Mutations | First gate: 20 of 30 caught. Re-check: 17 of 23 caught (4 of the 8 earlier survivors, and 13 of 15 new mutants on the cleanup list, filter, handler and wiring). |
| Repo hygiene | `.gitleaks.toml` unchanged; gitleaks 8.30.1 finds nothing in any commit. `check-commits` ok, deptrac and the DI rule 0 errors, `check-links` 211 links with 0 broken. The CHANGELOG from `## [2.` to EOF is identical to `main` `bbd53c4`. `docs/qa` files match the local copies (54). |

## Re-check (`2e74cd3..9738b9f`)

`Core\Http\RequestCleanup` replaces the per-object shutdown hook. `PlainHandler` runs it before it exits, a fatal error included, and the required after filter `requestcleanup` runs it at the end of every request. Both fatal kinds were reproduced over HTTP and CLI: no files are left, and disabling the handler call brings the leftovers back. The CI4 legs install the HEIC decoder and rerun the real-HEIC test with `--fail-on-skipped`; with `CI=true` and no decoder, the test fails.

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| M-20 | Medium | Closed | Upload cleanup didn't run after a fatal error under CI4's exception handler. It now runs through the per-request cleanup list. |
| L-21 | Low | Closed | Cleanup wasn't per request in worker mode. It is now a per-request service. |
| L-22 | Low | Closed | The real-HEIC test could skip in CI. CI now installs the decoder and fails on a skip. |
| N-23 | Nit | Not verifiable here | Deviations for the PR body: a symlinked served file gets 404; trailing or double slashes are normalised; NUL, backslash and LF get 400. All are stricter or harmless. The PR body isn't readable from here. |
| N-24 | Nit, tests | Partly closed | Now caught: anchored and lowercase names, invalid planted names, exact `If-None-Match`. Still uncaught: the HEIC header and area checks in `store()`, which the decoder repeats after the original is written. Also width 0 and the map-entry pattern (both close to equivalent). |
| N-30 | Nit | Open | A failing undo step is swallowed without a log line. Suggest logging the class and key, with no path. |
| N-31 | Nit, tests | Open | Two mutants survive: removing the undo `drop()` after a successful store, and a `break` after a failing step. Suggest a test that stores, runs the list and checks the files remain, plus a live step after the throwing one. |
| N-18, N-19 | | | Unchanged (Coder2's next batch). |

## Security

- Originals stay private and unchanged: never routed, not named by any served name, files 0640.
- `/media` serves only `<32hex>.jpg` from the served directories, through root-confined storage that refuses symlinks.
- Decode limits match `main` and run on the header before any write.
- Hardening M-20 and L-21 are closed: an unfinished upload is undone on an exception, a fatal error or the end of the request, in a worker-safe way.

**Verdict: PASS at `9738b9f1cb7578efda8f8beab64cc474e472ce43`** (0H / 0M / 0L; N-24 partly open, N-30 and N-31 open, N-23 not verifiable here).
