# QA: apotheke PR #70, security hardening on `main` (Host allow-list, sessions on demand, restore limit, HSTS docs, ImageMagick policy)

- **PR:** [#70](https://github.com/thordin9/apotheke/pull/70), "fix(security): harden Host, sessions, restore limit and HSTS docs", into `main`, by DevOps. Open, not a draft.
- **Base:** `main` at `a6e89c7`.
- **Head reviewed (final):** `0926503af6a3de60c0c3ae05ea841b9c86c11e48`, 8 commits on `a6e89c7`: `4c3f036`, `faab42e`, `ee6040d`, `36fb798`, `49f0136`, `924329d`, `36c1ac7`, `0926503`.
  - The first pass (§1–§3) was at `49f0136` (27 files, +798 / −26). §4 reviews the three later commits, commit by commit.
  - The head moved twice during the review: to `36c1ac7` at 11:30 and to `0926503` at 11:35 (Athens time, UTC+3).
  - I polled the PR every 2 minutes from 11:35 to 11:51 with no further push. The head was still `0926503` at the final check (§6).
- **CI:**
  - Final head: run 40078672407 at `0926503` (`pull_request`), **10/10 `success`** (11:36–11:46 Athens time, UTC+3).
  - First pass: run 40076465364 at `49f0136`, 10/10 `success` (11:02–11:12).
- **Date:** 2026-10-24 (Europe/Athens).
- **Scope:** read-only.
  - Sources: tarballs of `49f0136` and `a6e89c7` and the GitHub API (PR, commits, check runs). QA's audits were the baseline: `security-audit-apotheke-2026-10-24.md` (SEC-*) and `security-audit-apotheke-autofill-integration-2026-10-24.md` (S-*).
  - All local runs used scratch copies.
  - No comment, review, push or merge was made.
- **Verdict: PASS** at `0926503`. There are no High or Medium findings, only 1 Low finding (L-2) and 7 nits.
  - SEC-M3, SEC-L2 and SEC-L3 are fixed, and every repro is blocked.
  - Five of the six planned mutations fail the suite.
  - The ImageMagick policy matches S-M1 and S-L1, and a 48 MP HEIC and a 48 MP JPEG still decode under it.
  - L-1 (`16KP` read as 16,000 px) is **resolved** by `0926503`, which sets an explicit 16384 limit with a check that matches it.
  - L-2 and N-1 to N-7 are still open.

## 1. Test plan

### 1.1 Environment

- **Docker isn't available on this box.**
  - The PHP side ran on the box's PHP **8.4.27** CLI (gd, imagick, pdo_sqlite) under `php -S`, with SQLite in throwaway `DATA_DIR`s.
  - Composer dev dependencies came from the locked `composer.lock`.
  - CI covers the PHP 8.3 legs.
- **nginx:** there is no nginx binary on the box. The `auth_request` set-up was **checked by reading** `docker/nginx.conf` and `docker/fastcgi.conf`, and not run (§2.4).
- **ImageMagick:** the box's Imagick uses ImageMagick **7.1.1-43 Q16** (non-HDRI), with HEIC/HEIF read through libheif.
  - The PR's policy was applied with `MAGICK_CONFIGURE_PATH`, and Imagick reported the PR's limits (§2.6).
  - The image's ImageMagick is Q16-HDRI. To match its byte count, I also ran every case with a copy of the policy whose memory, map and disk limits were halved, because HDRI uses 4-byte floats and Q16 uses 2-byte integers.
  - The test HEIC files were made with libheif 1.23.6 / x265 (pillow-heif) in a scratch venv.
- Sign-in mode used a random throwaway `APP_PASSWORD` (written as `<APP_PASSWORD>` here) and a second account with the built-in Editor role, which has no Backup permission (`<editor password>`).
- gitleaks **8.30.1**, downloaded and checked against the sha256 pinned in `ci.yml`.

### 1.2 Acceptance checks

1. **CI** on the head (all of `ci.yml`'s checks), and the commit subjects through `scripts/check-commits.sh`.
2. **Repros:** SEC-M3, SEC-L2 and SEC-L3 re-run against the head. **Mutations:** each fix reverted on its own must fail the suite.
3. **SEC-M3:** the Host allow-list, the 421 without echo, labels, the same-origin rule with sign-in off, a list of bypass probes, LAN access by IP and `localhost`, and the compose port and `APP_PASSWORD` default left unchanged.
4. **SEC-L2:** session files counted per request type, the `/backup/restore/check` route, and the nginx `auth_request` set-up (read only).
5. **SEC-L3:** the `Secure` cookie, HSTS in the examples, the `trust_proxy` text, and the XFF and throttle code left untouched.
6. **ImageMagick policy (S-M1, S-L1):** the limits and denies, whether the check script fails on a loosened policy, and real photo sizes, including the steering items (a)–(c).
7. **Upgrade risk** (the 421), and whether the docs state the five design calls the PM approved.
8. **Docs, CHANGELOG and the public text** (Conventional Commits, links, hardening-level wording).
9. **Security** and the secret scan.

## 2. Results

### 2.1 CI and commits (item 1)

- **Run 40076465364** (`49f0136`, `pull_request`): **10/10 `success`**.
  - The checks: Lint, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Visual E2E shards 1–3, Docker build + smoke test, Conventional Commits and Secret scan.
  - The run went from 11:02 to 11:12 Athens time (UTC+3).
- **Main shows 11 checks against the PR's 10, and that's expected.**
  - Conventional Commits runs on PRs only (`if: github.event_name == 'pull_request'`).
  - "Version, changelog, tag and GitHub Release" is `release.yml`, which runs on `workflow_run` after CI on `main` and never on a PR.
  - So every `ci.yml` job that runs on a PR ran and passed.
- **`scripts/check-commits.sh --message`** on all 5 subjects: all `ok`, all ≤ 72 characters, with the types `fix(security)`, `fix(docker)`, `build(docker)`, `docs(deploy)` and `docs(changelog)`.
- **Local PHPUnit** at `49f0136` (PHP 8.4.27, SQLite): **OK, 384 tests, 2753 assertions, 11 skipped**. The skips are the PostgreSQL-only legs and the tools that aren't installed. The PR body says 381. The difference is in the test count, and nothing failed.

**PASS**

### 2.2 SEC-M3: Host allow-list and same-origin rule (items 2, 3)

**Host check, raw HTTP requests to `php -S`, sign-in off, nothing configured:**

| `Host` (or request) | Result |
|---|---|
| an unknown name, in upper case, with a trailing dot, or with a port | 421 |
| userinfo forms (`name@IP`, `IP@name`) | 421 |
| an IPv6 zone (`%25` or raw `%`) | 421 |
| decimal or short IPv4 forms (`2130706433`, `127.1`) | 421 |
| `IP.name`, `localhost.name` | 421 |
| a space, comma or tab in the value | 421 |
| port 0, or a port above 65535 | 421 |
| the same Host twice, in either order | 421 |
| an absolute-URI request line with an unknown `Host` | 421 (the app uses the `Host` header) |
| an absolute-URI request line naming another host, with an IP `Host` | 200 (the request line is not used) |
| `X-Forwarded-Host` with an unknown name, IP `Host` | 200 (the header isn't read) |
| `/healthz`, `/favicon.ico`, HEAD and POST with an unknown name | 421 |
| IPv4 with a port, `[::1]`, `[::ffff:a.b.c.d]` | 200 |
| `localhost`, `LOCALHOST:port`, `localhost.`, `*.localhost` | 200 |
| `gethostname()` | 200 |
| no `Host` (HTTP/1.0) or an empty `Host` | 200 |

- **The 421 body is fixed text**, and the refused name is not echoed in the body. `php -S` copies the `Host` request header into its response headers, but that comes from the PHP built-in server, not the app, and the nginx image doesn't do it.
- **No DNS-rebinding path is left.** A rebinding page sends its own name as `Host`, which gets 421.
  - IP literals can't be rebound.
  - Browsers resolve `*.localhost` to loopback themselves, and the public DNS root doesn't delegate `.localhost`.
  - A missing or empty `Host` can't come from a browser.
- **Duplicate Host and absolute-URI request lines under nginx (by reading):** nginx answers 400 to a duplicate `Host`, and the app only ever reads the header (`HTTP_HOST`).
- **Labels are not poisoned.**
  - On `/item/1/label` the link host follows the accepted `Host`: an IP, `[::1]` or `localhost` gives that host, and a missing `Host` gives `http://localhost/…`.
  - An unknown name gets 421 before the label is built.
  - `PublicUrlTest` covers the fallback.
- **LAN access works with nothing configured.** `192.168.x.x`, `127.0.0.1`, `[::1]` and `localhost` all give 200, as the suite checks too. The compose port and the `APP_PASSWORD` default are unchanged: `compose.yaml` and the `APP_PASSWORD` handling aren't in the diff.

**Same-origin rule, sign-in off, valid session and CSRF token, `POST /locations`:**

| Headers | Result |
|---|---|
| neither `Sec-Fetch-Site` nor `Origin` | 200 |
| `Sec-Fetch-Site: same-origin` or `none` (any case, or empty with no `Origin`) | 200 |
| `Sec-Fetch-Site: cross-site` or `same-site` (even with the app's own `Origin`) | 403 |
| `Origin` of another site, `null`, or the app's host on another port | 403 |
| the app's own `Origin` (upper-case scheme or trailing `/` too) | 200 |
| a foreign `Referer` only | 200 |
| PUT, PATCH or DELETE with a foreign `Origin` | 403 |
| no cookie: foreign `Origin` | 403 |
| no cookie: no headers | 419 (CSRF) |

**Assessment of "both headers missing is allowed":**

- The current versions of every major browser send `Sec-Fetch-Site` on every request.
- Browsers without it still send `Origin` on a cross-origin POST. A page with `Referrer-Policy: no-referrer` gets `Origin: null`, which is refused.
- A request with neither header therefore comes from a non-browser client. That client could set any header it likes anyway, and it still needs the session's CSRF token.
- So the gap opens no cross-site or rebinding path, and the CSRF token and `SameSite=Lax` cookie still apply.
- A spoofed `Sec-Fetch-Site: same-origin` next to a foreign `Origin` is accepted, because `Sec-Fetch-Site` wins. Only a non-browser client can send that pair, and the same token argument applies.

**PASS** (see N-6 for older browsers behind a TLS proxy).

### 2.3 SEC-L2: sessions on demand (items 2, 4)

I counted the files in `DATA_DIR/sessions` before and after each request, with no cookie.

| Request | Sign-in off | Sign-in on |
|---|---|---|
| GET of a 404, `/favicon.ico`, `/theme.css`, `/labels.css` or `/manifest.webmanifest` | +0 | +0 |
| GET `/healthz`, `/health`, `/backup/restore/check`, or an unknown item | +0 | +0 (anonymous gets 303 to `/login`) |
| POST `/locations`, `/login`, `/backup/restore`, `/items`, `/logout` or `/settings` (no cookie) | +0 (419/303/404) | +0 |
| a made-up session cookie on a 404 or a POST | +0 | +0 |
| GET `/settings` or `/backup` (pages with forms) | +1 each | n/a (sign-in required) |
| GET `/login` | n/a | **+1 per request** (3 requests made 3 files) |

- On `main`, every one of these requests made a file, as QA's SEC-L2 repro showed. At the head, only the pages with a form do.
- The `/login` row is the remainder of SEC-L2 that was deferred on purpose. See N-5 for the docs.

**`/backup/restore/check`:**

| Who | Result |
|---|---|
| sign-in off | 204 |
| not signed in | 401 |
| Editor (no Backup permission) | **403** |
| admin | 204 |
| an unknown `Host` | 421 |
| POST | 404 with sign-in off, 303 with sign-in on (no handler) |

- The responses have an empty body and `Cache-Control: no-store`, and set no cookie.
- A cross-site GET with `Sec-Fetch-Site: cross-site` gets the same codes, but a cross-site page can't read them. The route only reads the session, so it can't leak data or skip a check.
- Its user comes from the session alone. It runs inside the `try` after `init_session()`, before the permission redirect, and doesn't change state.

**PASS**

### 2.4 nginx `auth_request` (item 4, checked by reading; not run)

- `location = /backup/restore` has `auth_request /_restore_check;`.
  - nginx runs `auth_request` in the access phase, before the content phase reads the body.
  - A 401 or 403 from the subrequest goes straight to the client, and nginx throws the unread body away.
  - Any other status is an error (500).
- `client_max_body_size 520m` is checked earlier, against `Content-Length`. The internal location repeats it, because the subrequest is checked against the same header. That matches the comment.
- `location = /_restore_check` is `internal`, so a request from outside gets 404, as `docker-checks.sh` checks.
  - It doesn't include `fastcgi_params`, and it sets `REQUEST_METHOD GET`, `REQUEST_URI /backup/restore/check`, an empty `CONTENT_LENGTH`, `CONTENT_TYPE` and `QUERY_STRING`, `HTTP_PROXY ""` and `fastcgi_pass_request_body off`.
  - `fastcgi_pass_request_headers` defaults to on, so `HTTP_HOST` and `HTTP_COOKIE` reach PHP, and the session and Host check work.
  - `HTTPS $https if_not_empty` keeps the scheme.
- PHP-FPM reads no body, because `CONTENT_LENGTH` is empty. The user can't change the method or the URI.
- CI's `docker-auth-checks.sh` passed in run 40076465364. It checks that a 30 MB restore gets 401 before sign-in and reaches the app (422) after it.
- See N-3 for a POST to `/backup/restore` with an unknown `Host`.

**PASS** (by reading)

### 2.5 SEC-L3: cookie, HSTS, `trust_proxy` (items 2, 5)

With `PUBLIC_URL=https://inv.example.net`, on plain HTTP:

| `Host` | `Set-Cookie` |
|---|---|
| `inv.example.net`, `INV.example.net.` or `inv.example.net:443` | `secure; HttpOnly; SameSite=Lax` |
| `127.0.0.1:port` | `HttpOnly; SameSite=Lax`, no `secure` |
| `X-Forwarded-Proto: https` with `trust_proxy` off | no `secure` |

- **HSTS:** the Caddy example has `header Strict-Transport-Security "max-age=31536000"`, and the nginx example has `add_header … always;` plus `X-Forwarded-Proto`. The Security list says the app doesn't send HSTS itself.
- **`trust_proxy` docs are accurate.** `request_is_https()` reads only the first `X-Forwarded-Proto` value, and only when `trust_proxy` is on. The legacy app reads no other forwarded header. The Control Panel group name ("Users & sign-in") matches `settings.php`.
- **The XFF and throttle code is untouched.** `src/users.php` is byte-identical to `main`, so SEC-M2 is left to its own PR.

**PASS**

### 2.6 ImageMagick policy (item 6)

**The policy matches S-M1 and S-L1.**

- `width`/`height` are `16KP`, `area` `128MP`, `memory`/`map` `256MiB`, `disk` `1GiB` and `time` `60`.
- `delegate * none`, the coder list `{MVG,MSL,TEXT,TXT,LABEL,CAPTION,URL,HTTP,HTTPS,FTP,XPS,PS,EPS,PDF,SVG,MSVG,EPHEMERAL}` set to none, and `path @*` set to none.

**With `MAGICK_CONFIGURE_PATH`, Imagick reports:**

| Limit | Value |
|---|---|
| width, height | **16000** |
| area | 128000000 |
| memory, map | 268435456 |
| disk | 1073741824 |

**`scripts/imagick-policy-check.php`:**

| Policy | Exit |
|---|---|
| the PR's policy | 0, all checks `ok` |
| no PR policy (the box's default only) | 1 (14 FAILs) |
| width and height raised to `32KP` | 1 |
| the coder rule removed | 1 |
| `path @*` loosened to `read` | 0 (N-2) |
| delegates loosened to `read\|write` | 0 (N-2) |
| `time` raised to 3600 | 0 (N-2) |

**(a) `16KP` = 16,000 px, not 16,384.**

- ImageMagick reads `K` here as an SI prefix. The box's own default policy shows the same thing: `32KP` is reported as 32000.
- The PR's check script also says "KP and MP are decimal (16KP = 16000 px)". A 16,001 px-wide JPEG is refused with "width or height exceeds limit".
- **So a 16,320 × 12,240 (200 MP) photo is refused, by width as well as by area (199.8 MP > 128 MP).**
- **That isn't a regression.** Without the policy, Imagick decodes it, and then the app's own 60 MP cap refuses it in `image_store_heic()` and `autofill_analysis_imagick()`. GD's path refuses more than 60 MP before decoding. So a 200 MP photo gets the placeholder today too.
- The regression from 16,000 is the narrow case in L-1.

**(b) Decode tests.** The steps copy `image_store_heic()`: read, auto-orient, the 60 MP cap, `imagick_match_gd()`, Lanczos resize to 2560 px, JPEG, strip. Times are from the box.

| Input | Box default policy | PR policy | PR policy, memory/map/disk halved (HDRI-equivalent bytes) |
|---|---|---|---|
| 48 MP HEIC 8000×6000 | OK, 5.5 s | **OK, 5.5 s** | **OK, 5.6 s** |
| 48 MP JPEG 8000×6000 (Imagick path) | OK, 1.6 s | OK, 1.8 s | OK, 1.9 s |
| HEIC panorama 16000×3000 (48 MP) | OK | OK | OK |
| **HEIC panorama 17000×3000 (51 MP)** | **OK** | **refused** (width) | refused |
| JPEG 16001×1000 (Imagick path) | OK | refused (GD path unaffected) | refused |
| 200 MP JPEG 16320×12240, plain read | decodes, then the app's 60 MP cap refuses it | refused (width/area) | n/a |
| 200 MP JPEG with `jpeg:size=4000x4000` | OK, decoded at 6120×4590 | **OK, 6120×4590** | n/a |

- **(b) result:** a 48 MP HEIC decodes under memory/map 256 MiB plus disk 1 GiB. In Q16-HDRI, 48 MP × 4 channels × 4 bytes is about 768 MB of pixel cache. That is more than memory + map, so the cache goes to the disk tier (1 GiB), and the decode works. The halved-limit run gives the same ratio for this box's Q16 build and also passed.
- **Caveat:** this is an equivalent-bytes run on a Q16 build, not a run on the image's HDRI build. The CI HEIC round-trip in `docker-heic.sh` ran inside the real image and passed, but with a small fixture.
- **(c) result:** a 200 MP JPEG with the `jpeg:size` hint decodes at 28.1 MP, well under 128 MP. #70 doesn't set that hint, because the app-side handling belongs to the separate S-M1 PR (#71).
- **HEIC round-trip:** CI's `docker-heic.sh` (policy check, HEIC upload with an unchanged sha256, stripped served and analysis JPEGs, CMYK and transparency fallback) passed in the Docker job.

**PASS**, with L-1 and N-2.

**Interaction with #71 (information only, not a #70 finding):** a 128 MP area cap, the 60 MP app cap and a `jpeg:size` pre-scale fit together. If #71 adds `setResourceLimit()` calls, they can only tighten these limits, so #71 should test against this policy.

### 2.7 Upgrade risk and the five design calls (item 7)

| Design call (approved by the PM) | Where the docs state it | Clear? |
|---|---|---|
| IP and `localhost` stay accepted | Host names section, README env row, CHANGELOG | Yes |
| `/healthz` stays behind the Host check | nowhere in the docs (tests only) | **No (L-2)** |
| 421 upgrade note | the upgrade bullet "Host names (this version)" in deployment-and-operations, and CHANGELOG ("If you open the app by a name, add it…") | Yes |
| nginx copy step | "copy the `auth_request` part of `docker/nginx.conf`" in Sessions and the restore upload | Yes. `36c1ac7` expands it into numbered steps (§4.2). |
| SEC-L2 remainder deferred | "created only when a page with a form is shown or someone signs in" | Only implied (N-5) |

The Docker `HEALTHCHECK` uses `127.0.0.1`, so it keeps working.

**PASS**, with L-2 and N-5.

### 2.8 Docs, CHANGELOG and public text (item 8)

- **CHANGELOG:**
  - Three `### Fixed` bullets and two `### Maintenance` bullets, each linking #70.
  - They use hardening wording with the IDs SEC-M3, SEC-L2, SEC-L3, S-M1 and S-L1.
  - There are no repro steps.
- **Commit bodies and PR body:** hardening-level throughout. Two places describe behaviour rather than a weakness, which is fine:
  - The rule that requests without both headers are allowed is documented behaviour, also in the docs.
  - "a 30 MB restore gets 401 before sign-in" describes a test.
- **No public text gives too much away.** There are no exploit steps, payloads or bypass wording.
- **Docs:** `docs/{architecture,ci,configuration,deployment-and-operations}.md`, README, AGENTS.md and `config.example.php` are consistent with the code. The exceptions are N-4 (PR body) and N-7 (README).

**PASS**

### 2.9 Mutations (item 2)

I ran each mutation in a scratch copy with the full PHPUnit run.

| Mutation | Result | Caught by |
|---|---|---|
| Host check removed from `public/index.php` | **fails** | HTTP suite: "unknown host name → 421", label, health, port range |
| same-origin check turned off | **fails** | HTTP suite: cross-site, same-site, foreign `Origin`, `Origin: null` → 403 |
| `session_cookie_live()` always true | **fails** | HTTP suite: cookieless 404, restore check, session file counts |
| `session_cookie_secure()` back to `request_is_https()` only | **fails** | `SessionCookieTest::testHttpsPublicUrlMarksCookieSecureOnItsHost` |
| `public_url()` back to an unchecked `Host` | **fails** | `PublicUrlTest::testUnlistedHostFallsBackToLocalhost` |
| restore check always 204 | **fails** | HTTP suite: "restore check → 401 when not signed in" |
| restore check 403 → 204 (signed in without Backup) | **survives** | nothing (N-1) |

## 3. Security

- **Secret scan:**
  - gitleaks 8.30.1 on the PR's added lines: no leaks.
  - On the full head tree it finds one generic-api-key hit in `tests/Unit/MigrateDsnTest.php:129`. That is a test fixture, and it is in `main` (`a6e89c7`) too.
  - CI's Secret scan passed.
- No credentials appear in the diff. CI's new sign-in step uses `openssl rand` for `APP_PASSWORD`. This doc uses placeholders only.
- **New surface:** one route, `GET /backup/restore/check` (status only, §2.3), and one internal nginx location (§2.4). Both are fine.
- The refusal paths (421, 403, 419) echo nothing that the request supplied.

## 4. Delta `49f0136` → `0926503` (three commits)

`diff -ruN` of the two trees covers 8 files. Every commit subject passes `scripts/check-commits.sh --message`:

| Commit | Subject | Length |
|---|---|---|
| `924329d` | `docs(changelog): open the next release notes with an upgrade note` | 65 |
| `36c1ac7` | `docs(deploy): add numbered nginx steps for the restore check` | 60 |
| `0926503` | `build(docker): cap ImageMagick width and height at 16384 px` | 59 |

The commit bodies are hardening-level.

### 4.1 `924329d`: CHANGELOG upgrade note, `docs/releasing.md`, `ReleaseTest`

- **CHANGELOG:**
  - An **Upgrade note:** paragraph now sits above the first `###` under `## [Unreleased]`. It names the always-accepted hosts, the 421, an `ALLOWED_HOSTS=nas.local,apotheke.example` example, the `PUBLIC_URL` alternative, says IP access needs no change, and links Host names.
  - It is plain prose and a code block, not a bullet, so it needs no PR link.
  - The five #70 bullets are unchanged and still linked, with hardening wording and IDs only. There is no exploit wording.
- **`release.php` really puts the note at the top.**
  - I ran it in a throwaway git copy of the head tree: tag `v2.6.1` at `VERSION`, one `fix(security)` commit, `origin` set to the repo URL.
  - `check-links` exits 0. `plan` gives patch → 2.6.2.
  - `apply --notes=… --date=2026-10-24` writes `## [2.6.2]` with the note as its first block, and leaves `## [Unreleased]` empty.
  - The release notes file begins with "**Upgrade note:** …", then the `ALLOWED_HOSTS=` block, then `### Fixed`, then `### Maintenance`, then the compare link.
- **`docs/releasing.md`** describes the rule accurately: text before the first `###` opens the release section, and any bullets in it need PR links.
- **`ReleaseTest`:** two new tests, which pass.
  - The first builds a synthetic changelog and checks the fold order and that a paragraph and code block need no link.
  - The second reads the real CHANGELOG and checks that the note leads the notes and comes before `### Fixed`. It skips when no note is present, which is right once the release has consumed it.
  - They use only `preg_match`, `str*` and the release functions, so they're not version-specific. I didn't run PHP 8.3 locally, and CI's 8.3 legs passed.
- **PHPUnit at `36c1ac7`** (PHP 8.4.27, SQLite): **OK, 386 tests** (the 384 from before plus the 2 new ones), 2758 assertions, 11 skipped. `0926503` changes no PHP test or app code.

### 4.2 `36c1ac7`: numbered nginx steps (`docs/deployment-and-operations.md`)

- Self-hosted nginx now gets four clearly marked steps:
  1. Check for `auth_request` with `nginx -V`.
  2. Copy both locations, keeping `client_max_body_size 520m` in both, with the reason given.
  3. Run `nginx -t` and reload.
  4. Check with curl: 401 signed out, or 204 with sign-in off.
- The snippet matches `docker/nginx.conf` line for line.
- The claim that a smaller limit on the check location gives 500 agrees with how `auth_request` handles a non-2xx/401/403 subrequest (§2.4).
- Correct, and hardening-level.

### 4.3 `0926503`: policy width and height 16384

- `docker/imagemagick-policy.xml`: `width`/`height` change from `16KP` to `16384`. `area 128MP`, `memory`/`map 256MiB`, `disk 1GiB`, `time 60` and the denies are unchanged.
- The Dockerfile grep, `scripts/imagick-policy-check.php` (`$want` and a 16,385 px probe) and `scripts/docker-heic.sh` all expect 16384. `docker-heic.sh` also prints `magick -list policy` and greps it for 16384, 128MP, 256MiB and 1GiB.
- The docs now say 16,384 px.
- CI's Docker job passed in run 40078672407, so `magick -list policy` in the image does show `16384`.

**Box tests with `MAGICK_CONFIGURE_PATH`:**

- Imagick reports width = 16384.
- 16,384 px-wide JPEG and 16,384 px-high PNG: **read**. 16,385 px for each: **refused**.

**Check script exit codes:**

| Policy | Exit |
|---|---|
| the head's policy | **0** |
| no policy | **1** |
| width/height `16KP` (got 16000) | **1** |
| width/height `20000` (also fails the 16,385 px probe) | **1** |
| width/height `32KP` (32000) | **1** |
| width/height `32768` | **1** |

**Value and memory.** The head has **16384** px per side. A PM message mentioned 20,000. As asked, I recorded the value and didn't grade it.

- Memory stays bounded whatever the side limit is: the pixel cache is capped by `area 128MP` and by memory 256 MiB + map 256 MiB + disk 1 GiB, all unchanged.
- The app's 60 MP cap still applies after decode.
- The §2.6 results still hold: a 48 MP HEIC decodes, and a 200 MP 16,320 × 12,240 photo is refused by area as before (not a regression).
- **L-1 is resolved.** The finding was that `16KP` silently meant 16,000. The limit is now explicit, documented and checked. HEIC photos wider than 16,384 px are refused by design, which is the PM's call.

### 4.4 Findings re-checked at `0926503`

| Finding | Status |
|---|---|
| L-1 | **Resolved** (§4.3) |
| L-2 | Open. "Host names" and "Monitoring and logs" still don't say that `/healthz` and `/health` are behind the Host check. |
| N-1 | Open. No new test covers the 403. |
| N-2 | Open. The new greps check the limit values, but not the `path`/`delegate` rights or `time`. |
| N-3 | Open. The new steps mention 500 for a too-small limit, but not 500 for an unknown `Host`. |
| N-4 | Open. The PR body text wasn't changed. |
| N-5 | Open. The sessions section is unchanged. |
| N-6 | Open. |
| N-7 | Open. |

**Secret scan of the delta:** gitleaks 8.30.1 on the 103 added lines of `49f0136`→`0926503`: no leaks. CI's Secret scan passed.

## 5. Findings

| ID | Grade | Finding |
|---|---|---|
| L-1 | Low, **resolved in `0926503`** | `16KP` is 16,000 px, which refuses HEIC photos wider or taller than 16,000 px but under 60 MP (for example a 17,000 × 3,000 panorama). They decode today and would get the placeholder (the original is still kept). |
| L-2 | Low | The docs don't say that `/healthz` and `/health` are also behind the Host check, so an outside monitor that uses a host name gets 421 after the upgrade. |
| N-1 | Nit | No test covers the restore check's 403 for a signed-in user without Backup (the mutation survives). |
| N-2 | Nit | The policy build check doesn't fail when the `path @*` or delegate rights are loosened, or when `time` is raised. |
| N-3 | Nit | Under nginx, a POST to `/backup/restore` with an unknown `Host` gives 500 instead of 421, because nginx treats the subrequest's 421 as an error. |
| N-4 | Nit | The PR body says `gethostname()` "is the container name in Docker". It is the container's host name, which is the container ID unless `hostname:` is set. The docs have it right. |
| N-5 | Nit | The docs only imply the deferred SEC-L2 remainder: each anonymous GET of `/login` still creates a session file. |
| N-6 | Nit | With sign-in off, behind a TLS proxy without `trust_proxy` or `PUBLIC_URL`, the `Origin` fallback expects `http://`. Browsers without `Sec-Fetch-Site` (such as Safari before 16.4) get 403 on writes. |
| N-7 | Nit | The README `ALLOWED_HOSTS` row lists the names that always work but leaves out the machine's host name (`docs/configuration.md` includes it). |

- **L-1, 16KP is decimal (resolved in `0926503`, which sets 16384; see §4.3).**
  - Measured: Imagick reports 16000, a 16,001 px image is refused, and a 17,000 × 3,000 HEIC decodes without the policy but not with it.
  - Only HEIC is affected, because HEIC always goes through Imagick. JPEG, PNG, WebP and GIF use GD first.
  - Suggested fix: set width and height to `32KP`, or to a plain number such as `16384` if the PM wants exactly that. `area 128MP` and the app's 60 MP cap still bound the memory. Update the check script's `$want` and the Dockerfile grep to match.
  - The 16,320 px-wide 200 MP photo is refused either way, by area and by the 60 MP cap, as it is today.
- **L-2, health checks.** Add a line to "Host names", for example: "`/healthz` and `/health` follow the same rule; the image's own `HEALTHCHECK` uses `127.0.0.1`, but an outside monitor that uses a name needs that name in `ALLOWED_HOSTS`." The same line could go in the upgrade note.
- **N-1:** add an Editor-role account to the sign-in section of `integration_hardening.php` and expect 403.
- **N-2:**
  - The Dockerfile greps only check that `@*` appears, not its rights.
  - Suggestion: have the PHP check try an `@file` read (with TXT allowed in a test copy) or parse `magick -list policy` for `Rights: None` next to `Path: @*` and `Delegate: *`.
  - Also check `Imagick::RESOURCETYPE_TIME`.
- **N-3:** harmless, but it logs an error. You could leave it, or skip `auth_request` when the Host check fails, which isn't worth the complexity. A doc mention is enough.
- **N-5:** one sentence in "Sessions and the restore upload": the sign-in page still starts a session per visit, and old files are removed by PHP's session clean-up.
- **N-6:** mention in "Host names" that a TLS proxy setup should set `PUBLIC_URL` (already advised) or `trust_proxy`.
- **N-7:** add "the machine's host name" to the README row.

## 6. Verdict

**PASS** for `0926503af6a3de60c0c3ae05ea841b9c86c11e48`: 0 High, 0 Medium, 1 open Low (L-2) and 7 Nits. L-1 is resolved.

- CI run 40078672407: 10/10 `success`.
- The head was re-checked at the end of the review and was still `0926503`.
- L-2 (one doc line about the health endpoints and the Host check) is worth adding before the merge, but it doesn't block it.
