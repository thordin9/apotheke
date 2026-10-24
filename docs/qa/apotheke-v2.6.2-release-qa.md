# v2.6.2 release QA

Release: [v2.6.2](https://github.com/thordin9/apotheke/releases/tag/v2.6.2), cut automatically from main after PR
[#70](https://github.com/thordin9/apotheke/pull/70) (`fix(security): harden Host, sessions, restore limit and HSTS
docs`) merged as `242c53430dd858dbfe7bb5895e29f3544c17aae4`, pinned to `c7478ce`. It also carries PR
[#62](https://github.com/thordin9/apotheke/pull/62) (v2.6.1 follow-ups: tests, CI and docs only), which merged after
v2.6.1 and cut no release of its own. Previous release: v2.6.1.

**Verdict: PASS.** No High or Medium findings. One Low, four Nits and seven Info notes.

- The built image (reproduced from the Dockerfile, see §3) reports the ImageMagick policy, and the policy check passes.
- A 48 MP HEIC and a 48 MP JPEG are accepted through the real upload and autofill paths. The originals are kept
  byte for byte, and only a 1024 px JPEG goes to autofill. The 200 MP JPEG gets the clean "Image dimensions are too
  large." error. No request returned a 500.
- The 48 MP HEIC used about 560 MB of the 1 GiB `disk` limit, so raising it to 2 GiB is not needed.
- The Low (L-1) is in the upgrade note. "Setting `PUBLIC_URL` … works too" holds only on a new database. On an
  existing install the env var is ignored, because the Public address is a database setting.

## Test plan

1. Tag and release commit: is `v2.6.2` annotated; does it point at a `chore(release): v2.6.2 [skip ci]` commit whose
   only parent is `242c534`; is `c7478ce` (the hash-checked `docs(qa)` head) the merge's second parent; which files
   the release commit changes.
2. Main CI on `242c534` (every job, including the Docker job's PHP pin and HEIC/policy steps), the Release run, and the
   release's state, author, assets and edit time.
3. Release body and CHANGELOG: the Upgrade note at the top with the `ALLOWED_HOSTS` example; DevOps's hand-added
   health-probe line; the body compared with the CHANGELOG 2.6.2 section; PR links; compare links; version numbers.
4. Source archive (codeload): `VERSION`, migrations (26) and settings (63), Dockerfile `FROM`,
   `zend.exception_ignore_args`, the tree against `242c534` and `c7478ce`, `v2.6.1...v2.6.2` (commits, files), the QA
   docs in the release compared byte for byte with the QA originals, a fresh SQLite install and a v2.6.1 database.
5. The built image: no image is published, so I reproduced it from the Dockerfile in a chroot (§3.1 lists exactly
   how it differs). In it: `magick -list policy`, Imagick's limits, the build-time policy checks and
   `scripts/imagick-policy-check.php`.
6. Image behaviour on v2.6.2 (policy, but not #72's decode code), through the app's real `/save` upload and
   `/autofill/suggest` (stored `photo_id` and a direct `image` upload), against the archive's own autofill stub on
   loopback: a 48 MP HEIC, a 48 MP JPEG and a 200 MP (16,320×12,240) JPEG. For each: accepted or refused, what the
   user sees, peak RSS, time, the original's sha256, and what reached the stub.
7. Security smoke in the image (nginx + php-fpm): Host allow-list, the same-origin rule with sign-in off, session files
   for cookieless requests, health checks, the restore `auth_request`, the `Secure` cookie under an `https://` Public
   address, and the upgrade behaviour from the note (`nas.local` gets 421 until it is allowed).
8. PHPUnit (unit plus the HTTP integration suite, `tests/integration.php` with `integration_hardening.php`) at the tag,
   on PHP 8.3 and 8.4, with SQLite and PostgreSQL 17.
9. Secret scan: gitleaks 8.30.1 with the repo's config and the default rules; addresses in the added lines.

## Results

### 1. Tag and release commit

- Tag `v2.6.2` is an **annotated** tag (tag object `9e0b6f0b`, tagger github-actions[bot], message `v2.6.2`,
  12:15:09 EEST, unsigned like the earlier ones). It points at `6251870b63fff8dc133406d5d44d3e1b2c21e28b`,
  `chore(release): v2.6.2 [skip ci]` by github-actions[bot], whose only parent is `242c534`. `6251870` was still the
  head of `main` at 12:54 EEST.
- `242c534` ("Merge pull request #70 from thordin9/sec/host-session-hsts", 12:04:23 EEST) has parents `a6e89c7` (the
  #62 merge) and `c7478ce` (`docs(qa): add QA2 review for #70 security hardening`, on top of the QA-passed head
  `0926503`). The merge is pinned to the `docs(qa)` head that was hash-checked.
- The release commit changes exactly three files:
  - `VERSION`: 2.6.1 to 2.6.2.
  - `CHANGELOG.md`: the 2.6.2 header, the folded Unreleased text, the generated Maintenance lines and the compare
    links.
  - `README.md`: the coverage table. "Bootstrap, config, QR, seed" and "All PHP" rows changed.

### 2. Main CI and the Release run

CI run **40080507262** on `242c534` (push to main, attempt 1): **success**, 12:04:26 to 12:14:58 EEST.

| Job | Result |
|---|---|
| Lint | success |
| Secret scan | success |
| Tests (PHP 8.3, sqlite) | success |
| Tests (PHP 8.3, pgsql) | success |
| Headless mobile checks | success |
| Docker build + smoke test | success (step 6 "Assert the image runs the pinned PHP patch" and step 8 "HEIC, ImageMagick policy and startup refusal": success) |
| Visual E2E (Playwright, shards 1–3) | success |
| Conventional Commits | skipped (push event; it runs on pull requests) |

The only skipped steps are the failure-only report uploads and the coverage steps in the pgsql job, as for v2.6.1.

Release run **40081200351** (workflow_run, head `242c534`, attempt 1): **success**, 12:15:00 to 12:15:15 EEST. One
job, "Version, changelog, tag and GitHub Release". Every step is green, including "Only release the commit CI tested",
"Plan", "Refresh README coverage table from the CI artifact", "Update VERSION and CHANGELOG.md, commit, tag and push"
and "GitHub Release".

Release id 422629243: published 12:15:12 EEST, not a draft or a prerelease, name `v2.6.2`, author
github-actions[bot], target `main`, **no assets** (as for v2.6.1). Its `updated_at` is **12:25:17 EEST**, which is
DevOps's hand edit (§3). A patch bump is right for a hardening release.

### 3. Release body and CHANGELOG

- **Upgrade note at the top:** yes. The body opens with "**Upgrade note:** …", lists the always-accepted hosts and the
  421, and has the `ALLOWED_HOSTS=nas.local,apotheke.example` code block, the `PUBLIC_URL` alternative ("works too";
  see L-1), "Opening the app by its IP address needs no change" and a Host names link. Then come `### Fixed` and
  `### Maintenance`.
- **The health-probe line is there.** At first fetch (12:25) the body was the generated one (`updated_at` =
  `published_at`). By 12:36 it had DevOps's edit, which adds one paragraph after the note: "Health checks (`/healthz`
  and `/health`) follow the same rule. A probe that uses `localhost` or an IP address needs no change, but one that
  calls the app by a name needs that name in `ALLOWED_HOSTS` or `PUBLIC_URL`." It adds one trailing blank line and
  changes nothing else. This answers #70 L-2 for the release. The CHANGELOG line follows in DevOps's PR.
- **Body vs CHANGELOG 2.6.2:** before the edit, the body up to the `**Full changelog:** …/compare/v2.6.1...v2.6.2`
  link matched the section exactly (29 lines each, after normalising line endings). After the edit, it differs only
  by the health paragraph, which is expected until the follow-up PR.
- **`## [Unreleased]`** is empty. The hand-written text of `242c534`'s Unreleased (note, three Fixed bullets, three
  Maintenance bullets) moved into 2.6.2 unchanged.
- **Generated lines and dedup.** #70's generated `security:`, `docker:`, `deploy:` and `changelog:` lines were
  dropped, because hand-written bullets use those scopes. #62's `autofill:`, `release:` and `qa:` lines and #70's
  `qa:` line stayed. N-1 and N-2 below cover the leftovers.
- **PR links:** every bullet links its PR, either #70 or #62. The #62 change ("reject an Unreleased bullet with no PR
  link") worked: the 2.6.1 and 2.6.0 sections in the CHANGELOG now link #60 and #59, which resolves v2.6.1 L-1 in the
  CHANGELOG.
- **Links and versions:** `[2.6.2]` links `v2.6.1...v2.6.2`, and `[Unreleased]` now links `v2.6.2...HEAD`. `VERSION`
  is `2.6.2`, and `/health` in the image reports `"version":"2.6.2"`. The Host names link resolves to
  `docs/deployment-and-operations.md#host-names`, which exists.

### 4. Source archive

- `apotheke-2.6.2.tar.gz` from codeload: 33,016,266 B, sha256
  `b0233854c1469eea04d1ef2ba8b921c177e2e4e5ecb62530f9baa396b3b3884e`. GitHub generates the archive on request, so
  this hash is not stable over time.
- `VERSION` is `2.6.2`. There are 26 migrations, and the last is still `0026_autofill_max_image_bytes.php`.
  `Dockerfile` line 2 is `FROM php:8.3.36-fpm-alpine`. `docker/php.ini` line 12 is
  `zend.exception_ignore_args = On`.
- The archive differs from the `242c534` tree only in `CHANGELOG.md`, `README.md` and `VERSION`. The `242c534` tree
  is identical to `c7478ce`'s (`diff -r` is empty).
- **`v2.6.1...v2.6.2`:** 18 commits ahead, 0 behind, merge base `e2628f2`.
  - #62's commits: `fd5ba7a`, `6fdd20f`, `8c21714`, `14c57af`, `8896fd8`, `ad70a70`, then the merge `a6e89c7`.
  - #70's commits: `4c3f036` to `0926503`, then `c7478ce`, then the merge `242c534`.
  - Then the release commit `6251870`.
  - 36 files changed. Every change belongs to #62, #70 or the release commit.
- **QA docs in the release:**

| File | Size | sha256 | vs QA original |
|---|---|---|---|
| `docs/qa/apotheke-pr70-main-security-hardening-qa.md` | 30,207 B | `0fa77a942b3f2aa17ec82e545d0acc57c3925335bfb84cedc6bfddbad32d8e5e` | `cmp` identical |
| `docs/qa/apotheke-v2.6.1-release-qa.md` | 14,562 B | `4ed0e3df05c6e583e6b7adee0a05257d0e2340aef6647b900c07805da3f4e0ee` | `cmp` identical |
| `docs/qa/apotheke-pr62-v2.6.1-followups-qa.md` | 15,377 B | `677ec6093db42267ad29ef4f4d6c2e9cc6ed1f0cb1c740fded1f214c61b5ab07` | `cmp` identical |

  QA has local copies of 27 of the archive's QA docs, and all 27 are byte-identical. `2.4.0-qa.md` predates the
  local archive.
- **Migrations, SQLite** (PHP 8.4.27): a fresh install applies 26 steps, a second run says "Already up to date", and
  the settings table has 63 rows. A database created by v2.6.1's `bin/migrate.php` is "Already up to date" under
  v2.6.2, with 63 rows. The upgrade needs no database step. That v2.6.1 database also has a `public_url` row (`""`),
  which matters for L-1.

### 5. The built image

#### 5.1 How it was built, and how it differs from the real image

The release publishes no container image. The workflow builds `apotheke:ci` with `load: true` and pushes nowhere, and
ghcr.io refuses an anonymous pull token for `thordin9/apotheke`. So I reproduced the image from the tag's Dockerfile,
without Docker:

- **Base:** `php:8.3.36-fpm-alpine`, pulled with curl from Docker Hub's registry API using an anonymous token.
  - Index digest `sha256:aaf1ad25fc6484613be770743d1038e98e3e7e07409d78b97410eccdf3628f75`, amd64 manifest
    `sha256:4bba7da77c6a8473122b7826df62763b5639d89b1eedfe6d38ffa10bb222f4fa`.
  - 12 layers, each checked against its digest and unpacked in order into a scratch rootfs. No whiteout files were
    present.
  - Alpine 3.24.3, PHP 8.3.36.
- **Build:** the Dockerfile's three `RUN` steps were run verbatim (the same shell text, `ARG` values and
  `PHPIZE_DEPS`) inside the rootfs with `chroot`, in a private mount namespace. The `COPY` steps became `install`/`cp`
  to the same paths, owned by root. `ENV`, `WORKDIR` and the entrypoint were the same: `/sbin/tini --
  apotheke-entrypoint apotheke`, with `DATA_DIR=/data`, `APP_PASSWORD=""` and `TZ=UTC`.
  - Imagick 3.8.0 came from its GitHub tag, and the sha256 pin matched.
  - Packages: ImageMagick 7.1.2-34 Q16-HDRI (OpenMP), libheif 1.23.4, libde265 1.1.3, nginx 1.30.6.
  - Every build-time assertion passed.
- **Differences from a `docker build` / `docker run`:**
  1. The apk packages were resolved at 12:26 EEST today. CI built at 12:04, so the versions are very likely the same,
     but I can't prove it, because no image digest is published.
  2. The container shares the box's network, PID and UTS namespaces. nginx listened on a scratch port through `PORT`,
     and the machine's host name is the box's.
  3. tini was not PID 1, so it printed its subreaper warning. This made no functional difference in these tests.
  4. `/tmp` was a 3 GiB tmpfs, while in Docker it is the container's writable layer. ImageMagick's disk-tier pixel
     cache (`MAGICK_TMPDIR` defaults to `/tmp`) therefore sat in RAM-backed tmpfs. The `disk` limit counts bytes
     either way.
  5. `/data` was a bind mount of a scratch dir, with no compose file, no `HEALTHCHECK` runner and no cgroup memory
     limit.

#### 5.2 Policy in the image

- `magick -list policy` shows `Path: /etc/ImageMagick-7/policy.xml` with these settings:
  - resource limits: `time 60`, `disk 1GiB`, `map 256MiB`, `memory 256MiB`, `area 128MP`, `height 16384` and
    `width 16384`;
  - `Delegate rights: None pattern: *`;
  - `Coder rights: None pattern: {MVG,MSL,TEXT,TXT,LABEL,CAPTION,URL,HTTP,HTTPS,FTP,XPS,PS,EPS,PDF,SVG,MSVG,EPHEMERAL}`;
  - `Path rights: None pattern: @*`.
- Imagick (PHP 8.3.36, `ImageMagick 7.1.2-34 Q16-HDRI`) reports `WIDTH=16384`, `HEIGHT=16384`, `AREA=128000000`,
  `MEMORY=268435456`, `MAP=268435456`, `DISK=1073741824` and `TIME=60`.
- The Dockerfile's grep step passed, and so did `php /usr/local/lib/apotheke/imagick-policy-check.php`, with all 21
  checks `ok`:
  - six limits;
  - TXT, TEXT, MVG, MSL, LABEL, CAPTION, HTTP, HTTPS and XPS "refused by the policy", and FTP, URL and EPHEMERAL "not
    built in";
  - "a 16385 px wide image is refused", an ordinary PNG still decodes and encodes as JPEG, and HEIC/HEIF is still
    listed.

### 6. Image behaviour on v2.6.2 (in the image)

Setup:

- **Images** were generated in a scratch venv (Pillow 13, pillow-heif 1.8.1 with libheif 1.23.6 / x265): smooth
  photo-like content with light noise, and no EXIF.
  - 48 MP HEIC, 8000×6000, 5,567,200 B, sha256 `37865714761b7555203547ad58b7acdb40217cf2b650740125ba070eae3d83b0`.
  - 48 MP JPEG, 8000×6000, 8,111,594 B, sha256 `fed0e6f29cfc7254553e351ec17c14ae51bef0d5409e18f41bd2889a26059e98`.
  - 200 MP JPEG, 16,320×12,240, quality 75, 19,008,277 B (under the 20 MB upload limit), sha256
    `970865a6554fce7f87f95892c19c1d407b5a584340fe918de3cc2cac9e482c23`.
- **Autofill** pointed at the archive's own stub (`tests/stubs/autofill-stub.php`, run with the box PHP on loopback),
  with no token. No request left loopback.
- **Measurement:** the container was restarted before each request, so each php-fpm worker was fresh. Peak RSS is the
  highest `VmHWM` among the image's php-fpm workers during the request, sampled every 50 ms. "/tmp peak" is the
  highest use of the container's `/tmp`: the upload temp file plus ImageMagick's disk cache.
- **Requests:** sign-in off, with a session cookie and CSRF token from `/new`, sent with curl through nginx.
  - `POST /save` with `photo` is the Add item form.
  - `POST /autofill/suggest` with `photo_id` analyses the kept original.
  - `POST /autofill/suggest` with `image` is a direct upload, as from a client that does not resize first.

| Input | Path | Result (what the user gets) | Time | Peak RSS | /tmp peak |
|---|---|---|---|---|---|
| 48 MP HEIC | `/save` | **accepted**, 303 to `/?saved=2` | 8.05 s | 252 MB | 559 MB |
| 48 MP HEIC | autofill, `photo_id` | ok, suggestions | 3.48 s | 257 MB | 554 MB |
| 48 MP HEIC | autofill, `image` | ok, suggestions | 3.38 s | 257 MB | 559 MB |
| 48 MP JPEG | `/save` | **accepted**, 303 to `/?saved=1` | 1.77 s | 221 MB | 15 MB |
| 48 MP JPEG | autofill, `photo_id` | ok, suggestions | 1.29 s | 227 MB | 0 |
| 48 MP JPEG | autofill, `image` | ok, suggestions | 1.29 s | 227 MB | 15 MB |
| 200 MP JPEG | `/save` | **refused**, 422 with the form and "Image dimensions are too large." | 0.05 s | 26 MB | ≤ 35 MB (upload) |
| 200 MP JPEG | autofill, `image` | 200 JSON `{"ok":false,"code":"unsupported","message":"No suggestions: this kind of file cannot be read as a photo."}` (N-3) | 0.07 s | 40 MB | 36 MB (upload) |

- **Originals kept byte for byte.** `originals/<64 hex>.heic` has sha256 `37865714…d83b0` (5,567,200 B), and
  `originals/<64 hex>.jpg` has `fed0e6f2…9e98` (8,111,594 B). Both match the uploads exactly.
- **What other users see** is the stripped copies. The served photos are 1600×1200 JPEGs (HEIC: 411,347 B, JPEG:
  411,391 B), and the thumbnails are about 66 KB. None of them has an `Exif` or `ICC_PROFILE` segment. The HEIC
  became a real photo, not the 64×64 placeholder.
- **What went to autofill.** The stub logged one `/v1/suggest` per autofill request:
  - every time a `photo.jpg` of type JPEG, **1024×768**, no alpha;
  - 233,100 B from the JPEG and 222,588 B from the HEIC, against the stub's 10 MiB limit;
  - the same image for the `photo_id` and `image` paths.

  The original never reached the stub.
- **The 200 MP JPEG is refused before decoding.** `image_store()` refuses it with `getimagesize()` against the app's
  60 MP cap, in 0.05 s and 26 MB RSS. Nothing is written to `originals/`, `images/` or the database, so there were 2
  originals after all the 48 MP and 200 MP runs. On the autofill path, GD refuses it at more than 60 MP and Imagick
  refuses it under the policy's `area 128MP`, so no decode happens. The user gets the generic "cannot be read" text
  (N-3).
- **The 48 MP HEIC fits the policy.** The Q16-HDRI decode used about 545 MB of disk-tier pixel cache (559 MB `/tmp`
  peak minus the 5.6 MB upload and the session), about **53 % of `disk 1GiB`**, with RSS staying near 256 MB
  (`memory 256MiB`). **The 1 GiB disk limit is enough. DevOps doesn't need 2 GiB for this photo.** Each worker that
  decodes one at the same time needs about 0.55 GB of free `/tmp` (I-5).
- **Extra cases, near the app's 60 MP cap** (GD path, `memory_limit = 256M`):
  - 50 MP JPEG, 8165×6124: accepted, 2.18 s, 225 MB.
  - 59.99 MP JPEG, 8944×6708: accepted, 2.68 s, 269 MB.

  No 500, and the originals matched (`954057cf…`, `1f021030…`).
- php-fpm and nginx logged no errors or 5xx during any of these runs. The big files and the data dirs were deleted
  afterwards.

### 7. Security smoke (in the image, nginx + php-fpm)

**Host allow-list**, with nothing configured and sign-in off (`GET /`):

| `Host` | Result |
|---|---|
| `127.0.0.1:<port>`, `127.0.0.1`, `[::1]` | 200 |
| `localhost`, `LOCALHOST:<port>`, `app.localhost` | 200 |
| `nas.local`, `nas.local:9091`, `apotheke.example`, `evil.example` | **421** |
| `/healthz` with `nas.local` | 421; with the IP: 200. `/health` with the IP: 200 |

- The 421 body is the fixed text "Unknown host name. Open the app by its address, or add this name to ALLOWED_HOSTS
  (or PUBLIC_URL)." It doesn't contain the refused name in any of the 4 cases. The headers are
  `Content-Type: text/plain`, `Cache-Control: no-store` and no `Set-Cookie`, and nginx (unlike `php -S`) does not copy
  the `Host` header back.
- With `ALLOWED_HOSTS=nas.local,apotheke.example`: `nas.local:9091`, `NAS.LOCAL` and `apotheke.example` give 200,
  `other.example` gives 421, the IP gives 200, and `/healthz` with `nas.local` gives 200.
- With `ALLOWED_HOSTS=*`: `anything.example`, `nas.local` and `10.0.0.1` all give 200.
- **The upgrade behaviour matches the note.** With nothing set, `nas.local:9091` gets 421. With `nas.local` in
  `ALLOWED_HOSTS`, it gets 200, and the IP works either way. The `PUBLIC_URL` alternative works on a new database
  only (L-1):
  - Env `PUBLIC_URL=https://apotheke.example` on a **fresh** data dir: `apotheke.example` and `apotheke.example:443`
    give 200.
  - The same env var on an **existing** data dir: `apotheke.example` gets **421**, because the settings table already
    has `public_url = ""` from the first start.
  - Setting it with `bin/settings.php set public_url …` (the Control Panel's General → Public address) gives 200.

**Same-origin rule, sign-in off** (`POST /save` with a valid session and CSRF token):

| Headers | Result |
|---|---|
| none | 303 (saved) |
| `Sec-Fetch-Site: same-origin` / `none` | 303 |
| `Sec-Fetch-Site: cross-site` / `same-site` | **403** |
| `Origin` = the app's own address | 303 |
| `Origin` foreign, `null`, or own host on another port | **403** |
| no cookie, foreign `Origin` | 403 |

**Sessions, no cookie.** I counted the files in `DATA_DIR/sessions` around each request.

| Request | Sign-in off | Sign-in on (`APP_PASSWORD=<APP_PASSWORD>`) |
|---|---|---|
| GET of a 404 | 404, +0 | 303, +0 |
| GET `/favicon.ico`, `/theme.css`, `/manifest.webmanifest` | 200, +0 | not run |
| GET `/healthz`, `/health` | 200, +0 | `/healthz` 200, +0 |
| GET `/backup/restore/check` | 204, +0 | 401, +0 |
| POST `/save` (no cookie) | 419, +0 | 303, +0 |
| POST `/login`, `/backup/restore` (no cookie) | 303 / 419, +0 | not run |
| a made-up session cookie on a 404 | +0 | not run |
| GET `/new` (a page with a form) | 200, **+1** | n/a |
| GET `/login` | 303, +0 | 200, **+1 each** (the known SEC-L2 remainder, #70 N-5) |

**Restore `auth_request` under the image's nginx** (sign-in on):

- A 30 MB restore while signed out gets **401**, so the body is not passed to PHP.
- `/_restore_check` from outside gets 404.
- After signing in, the restore check gives 204, and a 30 MB restore without CSRF reaches the app (419).
- With an unknown `Host`, a 30 MB restore gets **500** (#70 N-3, known and queued in DevOps's follow-up).

**`Secure` cookie** (fresh data dir, `PUBLIC_URL=https://apotheke.example`, plain HTTP to nginx):

- `Host: apotheke.example` or `apotheke.example:443` gets `Set-Cookie: apothekesid=<id>; …; path=/; secure;
  HttpOnly; SameSite=Lax`.
- `Host: 127.0.0.1:<port>` gets no `secure`.
- `X-Forwarded-Proto: https` with `trust_proxy` off, by IP, also gets no `secure`.

### 8. PHPUnit and integration suites at the tag

`composer install` from the archive's lock gave PHPUnit 11.5.57. One run per leg, each with its own `TMPDIR`. The HTTP
integration suite (`tests/integration.php`, which requires `integration_hardening.php` and `integration_autofill.php`)
runs inside PHPUnit as `HttpSuiteTest`.

| PHP | DB | Result |
|---|---|---|
| 8.3.36 (box `php8.3`) | SQLite | **OK, 386 tests, 2,755 assertions, 12 skipped** |
| 8.3.36 | PostgreSQL 17.11 | **OK, 386 tests, 2,783 assertions, 2 skipped** |
| 8.4.27 | SQLite | **OK, 386 tests, 2,755 assertions, 12 skipped** |
| 8.4.27 | PostgreSQL 17.11 | **OK, 386 tests, 2,783 assertions, 2 skipped** |

- **Skips:**
  - SQLite legs: 8 `PgsqlSchemaCreateTest` and 2 `BackupTest` tests, which need PostgreSQL.
  - All legs: `OriginalsTest` (no php-cgi on the box) and `ReleaseTest`'s upgrade-note test. The release has consumed
    the note, so this skip is expected, as #70's QA said.
- 386 tests = #70's 384 plus 2 `ReleaseTest`, as in #70's QA at `36c1ac7`.
- **PostgreSQL set-up:** CI's public test password was not accepted for the `apotheke` role on this box's PG17. I
  didn't change that role. I created a throwaway role and database for these runs. The role needed superuser, as in
  CI, because `PgsqlSchemaCreateTest` creates and drops roles. Both were dropped afterwards, and the tests left no
  roles behind.
  - A first PG attempt with a non-superuser role failed only on `CREATE ROLE`, in 2 schema tests and the HTTP suite's
    PG set-up. That was the environment, not the code.
- CI's 8.3 sqlite and pgsql jobs and the Docker job are green on `242c534` (§2).

### 9. Secret scan

- No `.git`, `.env*`, private keys or certificates are in the archive.
- gitleaks 8.30.1 (`dir`) on a clean extract found **no leaks**, both with the repo's `.gitleaks.toml` and with the
  default rules.
- **Addresses in the 1,836 added lines:**
  - IPs: only `127.0.0.1`, `192.168.1.10` and `10.0.0.5`, all doc and test examples.
  - Hosts: only `*.example`, `*.example.net`/`.org`, `nas.local`, `localhost` and `github.com/thordin9/apotheke`
    links.
  - The only Worker URL is `https://autofill.example`.
  - There are no tunnel or production hosts and no tokens.

## Findings

### High

None.

### Medium

None.

### Low

- **L-1: "Setting `PUBLIC_URL` … works too" does not hold on an existing install.**
  - **Cause:** `public_url` is a database setting (`cfg:public_url` default; "DB — General → Public URL" in
    `docs/configuration.md`). The first start writes it into the settings table, and a v2.6.1 database has the row
    `public_url = ""`. From then on, the env var is not read.
  - **Effect:** a user who follows the note by adding `PUBLIC_URL=https://apotheke.example` to `.env` and upgrading
    still gets 421 on that name (§7). The IP still works, so nobody is locked out, and `ALLOWED_HOSTS`, the note's
    main instruction, works as described.
  - **Where:** the upgrade note in the CHANGELOG and the release body, DevOps's new health-probe line ("in
    `ALLOWED_HOSTS` or `PUBLIC_URL`"), and "Host names" in `docs/deployment-and-operations.md` ("set `PUBLIC_URL` to
    that address").
  - **Fix:** say "or set the Public address under Control Panel → General (`PUBLIC_URL` only applies to a new
    install)". The release body can be hand-edited now along with the health line, and the CHANGELOG and the doc can
    go in DevOps's follow-up. Doesn't block.

### Nit

- **N-1:** the 2.6.2 `### Maintenance` list has a blank line before its last bullet (`changelog:` from #62). That
  blank line was already in the hand-written Unreleased, and `rel_trim_blank_edges()` keeps blank lines in the
  middle, so GitHub renders two lists. Cosmetic.
- **N-2:** #62's change appears three times in 2.6.2 Maintenance: the generated `autofill: test: post a JPEG that
  exactly fills the cap` and `release: ci: reject an Unreleased bullet with no PR link`, plus the hand-written
  `changelog:` bullet that describes both. The scope dedup only matches equal scopes. Either drop the hand bullet or
  use matching scopes next time.
- **N-3:** a photo over 60 MP sent straight to `/autofill/suggest` (no browser resize) gets "No suggestions: this kind
  of file cannot be read as a photo." The truth is that it is too large. The form path gives the correct "Image
  dimensions are too large." This is pre-existing, and it fits #72's decode work. Browsers resize before autofill, so
  only non-browser clients see it.
- **N-4:** `docs/deployment-and-operations.md` shows `GET /health` returning `{"status":"ok","version":"1.1.0"}`. It
  returns the running version (`2.6.2`). This is pre-existing.

### Info

- **I-1: no published image.** v2.6.2 ships only GitHub's source archives, and CI builds `apotheke:ci` without pushing
  it. The image results come from a faithful rebuild (§5.1). They can't be tied to a digest, because there is none.
- **I-2: v2.6.2 is #62 + #70, not #70 alone.** #62 (tests, the CI link check, the 2.6.1/2.6.0 link fixes and two QA
  docs) merged after v2.6.1 without cutting a release, so it ships here. It has no runtime changes.
- **I-3: hand edit.** The release body now differs from the CHANGELOG 2.6.2 section by the health-probe paragraph
  (§3), as v2.6.0 and v2.6.1 did. DevOps's follow-up carries the line into the CHANGELOG.
- **I-4: README coverage.** "All PHP" moved from 64.3 / 91.5 / 96.0 % (6,814 lines) to 64.5 / 91.6 / 96.1 % (6,891
  lines). "Bootstrap, config, QR, seed" moved from 71.9 / 75.9 / 90.1 % to 72.3 / 76.6 / 90.4 %.
- **I-5: HEIC disk headroom.** A 48 MP HEIC uses about 0.55 GB of `/tmp` pixel cache per decode. The `disk` limit is
  per process. `pm.max_children = 10`, so many HEIC uploads at the same moment would need several GB of free space in
  the container's writable layer. #72's reduced decode should lower this.
- **I-6: known #70 items** reproduced in the image as expected, and queued for DevOps's follow-up:
  - N-3: 500 for a restore with an unknown `Host`.
  - N-5: `/login` makes a session per visit.
  - L-2 is now in the release body.
- **I-7: migration and upgrade.** There is no migration and no new setting. A v2.6.1 install upgrades with
  `docker compose build --pull && docker compose up -d`, after adding any host names it uses to `ALLOWED_HOSTS`.
