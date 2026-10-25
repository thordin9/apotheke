# QA: PR #102: CI4 step 11a (item pages, form, Undo, activity)

Reviewed head `618361c` (from `5af1e94`). Full gate. **Verdict: PASS**. No High or Medium findings. Two Low findings (L-80, L-81).

## Gates
- gitleaks `5af1e94..618361c`: no leaks. The `.gitleaks.toml` sha256 matches `b5bc9f98…3a8f`.
- check-commits: 4 ok. deptrac: 0 errors. DI rule: OK. lint: exit 0. check-links: 212 links, 0 broken.
- The CHANGELOG tail is identical to main `bbd53c4`. `docs/qa` is unchanged; the pr98, pr99 and pr101 docs are byte-identical.
- Suites (test PG role with CREATEROLE + `createrole_self_grant`, per N-71):
  - CI4 module suite on SQLite + PG: 422 tests, OK.
  - Full CI4 suite: 676 OK on SQLite and 676 OK on PG.
  - Full legacy suite (PHP 8.3): 676 OK on SQLite and 676 OK on PG.
- Playwright on CI4 (Chromium, desktop + pgsql-smoke on real PG), 16 specs including add/item/undo/history/expiry: 52 passed, 6 skipped (the 6 list entries), exit 0.

## Differential probes (legacy vs CI4, shipped router, same seed)
- **XSS.** Payloads went into name, notes (`javascript:`/`data:` links, `<img onerror>`, attribute breakouts), serial, lent_to, tag, location, category, activity details/actor, and the `?merged=`/`?undo=`/`?barcode=` parameters.
  - Pages checked: /item, the edit and add forms, the 422 form, the undo bar, /activity, and the fetch/JSON variants.
  - A DOM check found no `on*` attribute and no `javascript:`/`data:` URL, on either app. All 23 bodies are byte-identical to legacy once tokens are masked.
- **Permissions.** 267 cases: anon, viewer, create-only, edit-only, delete-only, tidier and admin, each on GET/HEAD of the pages, `/save` create and edit, 12 `id` forms (`1abc`, ` 1`, `+1`, `0x1`, `1.9`, `-1`, `[]`…), move/new location, delete, and Undo (cross-user, replay, upper-case, expired, missing/foreign/header CSRF).
  - Everything matches legacy except `_method` (N-84).
  - Items have no owner, so there is no per-user IDOR: any role with edit may edit any item, as on legacy.
- **Path confusion.** 5,994 cases: 9 routes × 38 path variants × GET/HEAD/POST × 6 roles. No case where CI4 succeeds or writes and legacy does not.
  - The only differences are the classes that predate this PR: 400 where legacy gives 404 for `;`/`%00`/`%0a`/`%09`/`%23`/overlong/`%ff`/`%5C`, and legacy's 500 on `%00`.
  - The absolute-form and `/./` differences are gone. **N-70 closed.**
- **Uploads (Stathis's requirement).**
  - JPEG with EXIF and a comment (3000×2000, orientation 6), PNG, GIF, PNG sent as `image/jpeg`, JPEG sent as `text/html`, a GIF+PHP/HTML polyglot, path filenames, a fake HEIC and the HEIC fixture: original kept **byte-identical**; the served copy is resized (800×1200), has no metadata or payload, and is what `/media/full` returns. Originals are never served.
  - Rejected with 422 and no files, identical on both apps: SVG, a 60000² PNG bomb, a truncated JPEG, an empty file, over `max_upload_mb`, and a small `MAX_FILE_SIZE`.
  - A failure mid-save leaves no table changes, but does leave orphan files (L-81).
- **Undo.**
  - Tokens are 128-bit and single-use: replay gives 410, an expired token is pruned with its files and gives 410, and GET is 404. CSRF is required.
  - Any session whose role has edit can use a token (N-82, same as legacy).
- **Item-view recording.** Same as legacy: GET and HEAD record a view; prefetch requests record too; fetch requests don't; device and user scope behave alike; the guest case works.
- **First visit.** Both the session cookie and the device cookie are set, and the session survives: same sid, same CSRF token, and a later POST works.
  - An attacker-chosen unknown sid is not adopted: a new id is issued. No fixation.
  - Sign-in rotation can't be tested on CI4 because /login isn't ported.
- **N-73 closed.** 25 adversarial summaries all behave as expected, including zero tests, all skipped, an earlier OK, trailing junk/spaces, ANSI colour and CRLF.
- **N-74 closed.** The list is keyed by file + describe › title, and the whole-spec skips are gone.
- **N-75 closed on PG 17.** A trigger message with `DÉTAIL :`, fake `in … on line N.` lines, a multi-line DETAIL and a HINT: 0 of 5 test values appear in the log. The first-line value is still logged (known).
- **Worker mode.** Layout is not a persistent service, so its lazy `$_GET['undo']` read and the `$_COOKIE`/`$_SERVER` reads are per request. No leak across requests (N-83).

## Mutants: 17/22 killed
- **Killed:**
  - permission filter: `/save` edit rule, N-70 path revert;
  - controller: move gate, missing-id 404, `photo2`, fetch not recorded;
  - Undo: token consume;
  - routes: undo/delete/activity permissions;
  - views: undo label and item-name escaping;
  - N-75 redaction; three N-73 matcher mutants; no transaction in the repository save.
- **Survived but equivalent:** U2 (no hex check; the query is parameterised) and C6 (`back` unchecked; no token sets `back` until 11b).
- **Effectively equivalent:** U1 (no expiry check). `/undo` prunes first; only the bar for an expired token would show.
- **Real survivors:** C5 (a delete with Undo on drops the files) and U4 (prune does not release files). See L-80.

## Findings
- **L-80. Undo file lifecycle is untested on CI4.**
  - With C5, Undo puts back rows whose photo and attachment files are already gone.
  - With U4, the files of expired deletes are never released (disk grows; originals stay).
  - Add a parity case: delete an item with photos, check the files stay, undo, then let the token expire and prune.
- **L-81. A failed save after a photo was stored leaves 4 files.** These are the served copy, the thumbnail, the original (byte-identical, so it may carry GPS) and its map entry. Same on legacy.
  - Cause: `ImageStore::store()` drops its RequestCleanup step when it returns, before `ItemRepository::save()` commits.
  - A new location or category typed into the form also stays after the failure (same on legacy).
  - Hardening: keep the cleanup armed until the save commits.
- **N-82. Undo tokens are not tied to a user or session.** They are 128-bit, travel in the URL, and the page sends `Referrer-Policy: same-origin`. Same as legacy.
- **N-83. Cloudflare-phase prerequisite.** The Layout and Favourites factories read superglobals. This is safe in FrankenPHP worker mode, but the Workers runtime needs the values to come from the request object. Not a blocker.
- **N-84. `_method=PUT|PATCH|DELETE` on POST routes gives 404 on CI4.** Legacy ignores the field and acts. This is stricter, not weaker, and already happened on the #99 routes.
- **N-85. `Ci4E2eSkipListTest` matches each title part as any quoted string in the spec.** It doesn't check that the describe › title structure exists.
- **N-86. Views are recorded for HEAD and for prefetch requests (`Sec-Purpose`/`Purpose`)**, as on legacy. Optional hardening on both apps.
