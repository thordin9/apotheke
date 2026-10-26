# Changelog

All notable changes to Apotheke are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).
Release sections are written by `scripts/release.php` when a pull request is merged to `main`
(see [docs/releasing.md](docs/releasing.md)); the version lives in `VERSION`.
Releases before 2.6.0 are summarised once per minor version; their full notes are in git history.

## [Unreleased]

## [2.6.19] - 2026-10-26

### Fixed

- **security:** hardening: a new user name with a null character anywhere is refused like any other invalid name when registering, adding or renaming an account, and a sign-in with such a name spends the same password-check time as an unknown name. ([#121](https://github.com/thordin9/apotheke/pull/121))

### Maintenance

- test: null-character names, address cap and after-commit drains ([#121](https://github.com/thordin9/apotheke/pull/121))
- test: queue migrate's drain cleanup up front on every PHP version ([#121](https://github.com/thordin9/apotheke/pull/121))
- **qa:** docs: PR 121 main auth hardening QA report ([#121](https://github.com/thordin9/apotheke/pull/121))

## [2.6.18] - 2026-10-26

### Fixed

- **security:** hardening: a sign-in name with a null character anywhere is refused like a wrong password, on the sign-in form and when turning sign-in back on, on both databases. ([#116](https://github.com/thordin9/apotheke/pull/116))

### Maintenance

- test: sign-in names with a null character on both drivers ([#116](https://github.com/thordin9/apotheke/pull/116))
- **qa:** docs: PR 116 main sign-in name QA report ([#116](https://github.com/thordin9/apotheke/pull/116))

## [2.6.17] - 2026-10-26

### Fixed

- **security:** hardening: password checks outside the sign-in form also count toward the per-address cap; clean-up after a save runs only after its commit, and one failing step no longer skips the rest; upgrading resets the per-address counters once for IPv4-compatible (`::/96`, including `::1`) and NAT64 (`64:ff9b::/96`) clients. ([#112](https://github.com/thordin9/apotheke/pull/112))

### Maintenance

- test: cover after-commit order, account keys and password caps ([#112](https://github.com/thordin9/apotheke/pull/112))
- docs: password checks count toward the address cap ([#112](https://github.com/thordin9/apotheke/pull/112))
- docs: keep the #112 changelog entry under Unreleased ([#112](https://github.com/thordin9/apotheke/pull/112))
- **qa:** docs: PR 112 main hardening follow-up 2 QA report ([#112](https://github.com/thordin9/apotheke/pull/112))

## [2.6.16] - 2026-10-26

### Fixed

- **security:** hardening: a successful sign-in no longer resets the per-address cap; IPv6 clients count by their /64 for it; turning sign-in back on is limited like a sign-in; photo adds at the same time stay within the photo limit; a replaced photo's files are removed only after the save commits. ([#111](https://github.com/thordin9/apotheke/pull/111))

### Maintenance

- test: cover password limits, admin races and photo adds ([#111](https://github.com/thordin9/apotheke/pull/111))
- docs: sign-in address cap and trusted proxies ([#111](https://github.com/thordin9/apotheke/pull/111))
- **qa:** docs: PR 111 main hardening follow-up QA report ([#111](https://github.com/thordin9/apotheke/pull/111))

## [2.6.15] - 2026-10-25

### Fixed

- **security:** hardening: sign-in limits now hold for requests that arrive together and also cover the current-password check and all names from one address; passwords and `APP_PASSWORD` with a null character are refused; the last active admin always stays; a photo save that fails leaves no files or new places, and a photo edit keeps its original. ([#106](https://github.com/thordin9/apotheke/pull/106))

### Maintenance

- **qa:** docs: drop QA reports older than v2.6.0 and add an index ([#88](https://github.com/thordin9/apotheke/pull/88))
- **changelog:** docs: summarise pre-2.6.0 releases and fix QA nits ([#88](https://github.com/thordin9/apotheke/pull/88))
- test: use 8-character APP_PASSWORD fixtures ([#88](https://github.com/thordin9/apotheke/pull/88))
- docs: trim README and docs, merge overlapping pages ([#88](https://github.com/thordin9/apotheke/pull/88))
- ci: allowlist S-L5 CI4 placeholder test keys in gitleaks ([#88](https://github.com/thordin9/apotheke/pull/88))
- **security:** test: cover sign-in limits, admins and photo saves ([#106](https://github.com/thordin9/apotheke/pull/106))
- docs: sign-in limits and password rules ([#106](https://github.com/thordin9/apotheke/pull/106))
- docs: link the changelog entry to its pull request ([#106](https://github.com/thordin9/apotheke/pull/106))
- **qa:** docs: PR 106 main hardening QA report ([#106](https://github.com/thordin9/apotheke/pull/106))

## [2.6.14] - 2026-10-24

### Fixed

- **auth:** hardening: a new sign-in after a session was ended now starts a fresh session lifetime. ([#87](https://github.com/thordin9/apotheke/pull/87))

### Maintenance

- test: new sign-in after a dropped session gets a new time ([#87](https://github.com/thordin9/apotheke/pull/87))
- docs: changelog entry for fresh sign-in time ([#87](https://github.com/thordin9/apotheke/pull/87))
- **qa:** docs: add v2.6.12 release QA doc ([#87](https://github.com/thordin9/apotheke/pull/87))
- test: cover remaining keepSignIn call sites ([#87](https://github.com/thordin9/apotheke/pull/87))
- **qa:** docs: add v2.6.13 release QA doc ([#87](https://github.com/thordin9/apotheke/pull/87))
- **qa:** docs: add PR 87 session re-sign-in QA doc ([#87](https://github.com/thordin9/apotheke/pull/87))

## [2.6.13] - 2026-10-24

### Fixed

- **ui:** the footer version label has a fixed width, so a release bump no longer shifts the centred footer links. ([#86](https://github.com/thordin9/apotheke/pull/86))

### Maintenance

- **e2e:** test: update the list footer baselines ([#86](https://github.com/thordin9/apotheke/pull/86))
- **changelog:** docs: add the footer version width fix ([#86](https://github.com/thordin9/apotheke/pull/86))

## [2.6.12] - 2026-10-24

**Upgrade note:** a sign-in from before the upgrade is stamped on its first request after the upgrade and ends `session_days` later (default 30). After that, lowering `session_days` ends older sessions on their next request. Migration 0028 keeps existing sessions valid, so the upgrade signs nobody out.

### Fixed

- **auth:** a sign-in ends on the server once it is older than `session_days`, counted from sign-in. A session from before the upgrade is stamped on its next request instead of being ended. The idle stamp moves at most once a minute. Sign-out of an already ended session redirects. Sign out everywhere (Your account, and `php bin/settings.php user signout NAME`) and a new password replace the account's session epoch (migration 0028), so older sessions are dropped. SEC-N6. ([#84](https://github.com/thordin9/apotheke/pull/84))

### Maintenance

- docs: state the server-side sign-in limit ([#84](https://github.com/thordin9/apotheke/pull/84))
- docs: record the session lifetime limit ([#84](https://github.com/thordin9/apotheke/pull/84))
- **e2e:** test: update the security settings baseline ([#84](https://github.com/thordin9/apotheke/pull/84))
- test: use 8-character password in session lifetime tests ([#84](https://github.com/thordin9/apotheke/pull/84))
- test: stale sign-out deletes the live session file ([#84](https://github.com/thordin9/apotheke/pull/84))
- docs: name migration 0028 in the changelog ([#84](https://github.com/thordin9/apotheke/pull/84))
- **qa:** docs: add v2.6.11 release QA doc ([#84](https://github.com/thordin9/apotheke/pull/84))
- **qa:** docs: add PR 84 session lifetime QA doc ([#84](https://github.com/thordin9/apotheke/pull/84))

## [2.6.11] - 2026-10-24

**Upgrade note:** stored secrets (the AI autofill access token) are encrypted only when `APP_KEY` or `APP_KEY_FILE` is set. Without a key nothing changes: they stay plaintext and the Control Panel says to set one. Set the key before you save a token, see [Stored secrets](https://github.com/thordin9/apotheke/blob/main/docs/configuration.md#stored-secrets).

### Fixed

- **security:** stored secrets are encrypted at rest when `APP_KEY` is set (S-L5). With no key they stay plaintext and the Control Panel says to set one. A configured but unusable key refuses secret saves. `php bin/settings.php key rotate` re-encrypts them. ([#78](https://github.com/thordin9/apotheke/pull/78))

### Maintenance

- **changelog:** docs: link stored-secret encryption pull request ([#78](https://github.com/thordin9/apotheke/pull/78))
- **visual:** test: refresh autofill screenshots for the key warning ([#78](https://github.com/thordin9/apotheke/pull/78))
- ci: assert the sodium extension in the image ([#78](https://github.com/thordin9/apotheke/pull/78))
- docs: show APP_KEY_FILE mount and key backup advice ([#78](https://github.com/thordin9/apotheke/pull/78))
- **security:** test: cover unusable-key and moved-address cases ([#78](https://github.com/thordin9/apotheke/pull/78))
- docs: correct key file permissions ([#78](https://github.com/thordin9/apotheke/pull/78))
- docs: use numeric gid 82 for key file permissions ([#78](https://github.com/thordin9/apotheke/pull/78))
- **visual:** test: re-render baselines for the wider v2.6.10 footer ([#78](https://github.com/thordin9/apotheke/pull/78))
- **qa:** docs: add v2.6.10 release QA doc ([#78](https://github.com/thordin9/apotheke/pull/78))
- **qa:** docs: add PR 78 stored-secret encryption QA doc ([#78](https://github.com/thordin9/apotheke/pull/78))

## [2.6.10] - 2026-10-24

**Upgrade note:** a new install's `APP_PASSWORD` now needs at least 8 characters, not only spaces (hardening, #75 QA L-2). A shorter one stops the first start with a message that doesn't show it. An install that already has an account is unaffected.

### Fixed

- **auth:** hardening (#75 QA L-2): `APP_PASSWORD` meets the same 8-character minimum as every other password. A shorter value stops the Docker start check (exit 78), `bin/migrate.php`, `bin/settings.php` (exit 1, no stack trace) and the first-account seed, and the value is never logged. ([#81](https://github.com/thordin9/apotheke/pull/81))
- **docker:** hardening (#75 QA L-1, N-2; #73 QA N-1, N-2): the start check is tested for a database that can't be read, and each try waits at most 5 s for PostgreSQL. The ImageMagick policy check fails on any delegate or `@file` path rule that grants rights. nginx's 421 on the restore upload has the app's short plain-text body. ([#81](https://github.com/thordin9/apotheke/pull/81))
- **release:** a handwritten Fixed bullet replaces the lines generated from the same PR's `fix:` commits, whatever their scope (v2.6.5 release QA L-1). ([#81](https://github.com/thordin9/apotheke/pull/81))

### Maintenance

- **docker:** test: cover the exit 78 when the database can't be read ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** docs: say what the sign-in start check may change ([#81](https://github.com/thordin9/apotheke/pull/81))
- **docker:** docs: bind the 1.x upgrade port examples to 127.0.0.1 ([#81](https://github.com/thordin9/apotheke/pull/81))
- **hosts:** docs: name the 2.6.2 Host rule that health checks follow ([#81](https://github.com/thordin9/apotheke/pull/81))
- **hosts:** docs: say PUBLIC_URL only works on a new install ([#81](https://github.com/thordin9/apotheke/pull/81))
- **health:** docs: use a version placeholder in the /health example ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** test: cover a failing account query and the admin seed ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** test: give the APP_PASSWORD fixtures 8 or more characters ([#81](https://github.com/thordin9/apotheke/pull/81))
- **docker:** test: rewrap the sign-in checks header ([#81](https://github.com/thordin9/apotheke/pull/81))
- **changelog:** docs: record the SEC-M3 follow-up hardening ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** test: settings CLI exits 1 on a short APP_PASSWORD ([#81](https://github.com/thordin9/apotheke/pull/81))
- **auth:** test: give the idle-session fixture an 8-character password ([#81](https://github.com/thordin9/apotheke/pull/81))
- **qa:** docs: add v2.6.9 release QA ([#81](https://github.com/thordin9/apotheke/pull/81))
- **qa:** docs: add PR #81 SEC-M3 follow-up QA ([#81](https://github.com/thordin9/apotheke/pull/81))
- **docs:** health checks follow the Host rule from 2.6.2, `PUBLIC_URL` only works on a new install (an existing one uses Control Panel → General), the `/health` example uses a version placeholder, the 1.x upgrade port examples bind to `127.0.0.1`, and the start check's wording says what it may change (#73 QA L-1, v2.6.2 release QA L-1 and N-4, #75 QA N-1 and N-3). ([#81](https://github.com/thordin9/apotheke/pull/81))

## [2.6.9] - 2026-10-24

### Fixed

- **auth:** a signed-in session ends after `session_idle_hours` hours with no request (default 168, at most 8760). The stay-signed-in limit is unchanged. The idle limit is set under Users & sign-in and is a sign-in setting on import, restore, and the command line. SEC-N6. ([#80](https://github.com/thordin9/apotheke/pull/80))

### Maintenance

- **changelog:** docs: record the idle session limit ([#80](https://github.com/thordin9/apotheke/pull/80))
- **e2e:** test: update the security settings baseline ([#80](https://github.com/thordin9/apotheke/pull/80))
- **qa:** docs: add #80 session idle hours QA doc ([#80](https://github.com/thordin9/apotheke/pull/80))
- **qa:** docs: add v2.6.8 release QA doc ([#80](https://github.com/thordin9/apotheke/pull/80))

## [2.6.8] - 2026-10-24

### Fixed

- **images:** a PNG above 26 megapixels is refused from the header before it is decoded, with the same message as an oversized photo. WebP and GIF stay at 40 megapixels. The cap is the size an alpha PNG still fits under the Docker image's memory_limit of 256M (peak 231931904 bytes with the upload held). A camera file saved before processing dies, including a fatal error, is removed before the request ends. Suggest returns that same message with status 422 when a decode is refused as too large. ([#79](https://github.com/thordin9/apotheke/pull/79))

### Maintenance

- **changelog:** docs: record the PNG upload hardening ([#79](https://github.com/thordin9/apotheke/pull/79))
- **auth:** test: pin web guest admin and CLI password off ([#79](https://github.com/thordin9/apotheke/pull/79))
- **changelog:** docs: keep the #79 note out of v2.6.6 ([#79](https://github.com/thordin9/apotheke/pull/79))
- **qa:** docs: add the v2.6.5 release QA report ([#79](https://github.com/thordin9/apotheke/pull/79))
- **qa:** docs: add v2.6.6 release QA doc ([#79](https://github.com/thordin9/apotheke/pull/79))
- **qa:** docs: add v2.6.7 release QA doc ([#79](https://github.com/thordin9/apotheke/pull/79))
- **qa:** docs: add #79 PNG cap and upload clean-up QA doc ([#79](https://github.com/thordin9/apotheke/pull/79))

## [2.6.7] - 2026-10-24

**Upgrade note:** Docker installs are private by default (hardening, SEC-M3). Compose publishes the app on `127.0.0.1:9091` only: if phones or other machines open it directly, change the port line in `docker-compose.yml` to `"9091:9091"` (all interfaces) or `"<LAN-IP>:9091:9091"` on purpose; a reverse proxy on the same server needs no change. The container also refuses to start with sign-in off. An install with sign-in on starts unchanged. Without sign-in, either turn it on with `docker compose run --rm -u www-data apotheke php bin/settings.php password` (setting `APP_PASSWORD` alone doesn't, once the database exists), or set `ALLOW_NO_LOGIN=1` to keep running without sign-in. A new install needs `APP_PASSWORD` or `ALLOW_NO_LOGIN=1`.

### Fixed

- **docker:** hardening (SEC-M3): compose publishes the port on `127.0.0.1` only, and the container refuses to start with sign-in off unless `ALLOW_NO_LOGIN=1` is set. ([#75](https://github.com/thordin9/apotheke/pull/75))

### Maintenance

- **docker:** test: check the sign-in requirement at container start ([#75](https://github.com/thordin9/apotheke/pull/75))
- **docker:** docs: localhost port and required sign-in (SEC-M3) ([#75](https://github.com/thordin9/apotheke/pull/75))
- **changelog:** docs: record the SEC-M3 Docker defaults ([#75](https://github.com/thordin9/apotheke/pull/75))
- **docker:** test: hand the scratch volume back before deleting it ([#75](https://github.com/thordin9/apotheke/pull/75))
- **release:** test: check the whole upgrade note leads the notes ([#75](https://github.com/thordin9/apotheke/pull/75))
- **qa:** docs: add the v2.6.3 release QA report ([#75](https://github.com/thordin9/apotheke/pull/75))
- **qa:** docs: add the v2.6.4 release QA report ([#75](https://github.com/thordin9/apotheke/pull/75))
- **qa:** docs: add the #75 docker localhost sign-in QA report ([#75](https://github.com/thordin9/apotheke/pull/75))

## [2.6.6] - 2026-10-24

### Fixed

- **auth:** the command line can change sign-in settings and admin accounts when guest browsing is on. ([#77](https://github.com/thordin9/apotheke/pull/77))

### Maintenance

- **changelog:** docs: note the CLI guest-browsing admin fix ([#77](https://github.com/thordin9/apotheke/pull/77))
- **changelog:** docs: keep the CLI admin note out of v2.6.4 ([#77](https://github.com/thordin9/apotheke/pull/77))
- **qa:** docs: add the #77 CLI admin with guest browsing QA report ([#77](https://github.com/thordin9/apotheke/pull/77))

## [2.6.5] - 2026-10-24

### Fixed

- **autofill:** refuse an over-limit photo on suggest ([#72](https://github.com/thordin9/apotheke/pull/72))
- **images:** a JPEG of up to 250 megapixels is kept and decoded already reduced, so the decoded frame stays within 128 megapixels. A HEIC or HEIF photo above 128 megapixels, or above 16,384 px on a side, is refused from the header before it is decoded. A 48 MP photo still passes. The camera file of a photo that is kept stays byte-identical. (S-M1, SEC-N5) ([#72](https://github.com/thordin9/apotheke/pull/72))
- **images:** a JPEG above 40 megapixels is decoded already reduced. Photos at or under 40 megapixels still go through GD. Imagick is used with an explicit format, and its resource limits are set at startup. (S-M1, SEC-N5) ([#72](https://github.com/thordin9/apotheke/pull/72))
- **images:** a photo Imagick refuses because of a resource limit, including a HEIC frame the decoder will not allocate, is declined with the same “too large” message as an oversized frame. The upload is not stored, and the page does not show a server error. A photo over that same header limit posted to `/autofill/suggest` gets `Image dimensions are too large.` with status 422, and nothing is stored or sent on. (S-M1, SEC-N5) ([#72](https://github.com/thordin9/apotheke/pull/72))

### Maintenance

- **images:** docs: note the limit on photo dimensions ([#72](https://github.com/thordin9/apotheke/pull/72))
- **changelog:** docs: record the large-photo decode limits ([#72](https://github.com/thordin9/apotheke/pull/72))
- **images:** test: require the iloc error only when reported ([#72](https://github.com/thordin9/apotheke/pull/72))
- **changelog:** docs: keep #72 notes under Unreleased ([#72](https://github.com/thordin9/apotheke/pull/72))
- **qa:** docs: add the #72 image decode limits QA report ([#72](https://github.com/thordin9/apotheke/pull/72))

## [2.6.4] - 2026-10-24

**Upgrade note:** Health checks (`/healthz` and `/health`) follow the same rule. A probe that uses `localhost` or an IP address needs no change, but one that calls the app by a name needs that name in `ALLOWED_HOSTS` or `PUBLIC_URL`.

### Fixed

- **docker:** hardening (SEC-M3, #70 N-3): a restore upload with an unknown host name gets 421 from nginx, not 500. ([#73](https://github.com/thordin9/apotheke/pull/73))

### Maintenance

- **security:** test: cover the restore check 403 without Backup ([#73](https://github.com/thordin9/apotheke/pull/73))
- **changelog:** docs: say health checks need an allowed host ([#73](https://github.com/thordin9/apotheke/pull/73))
- **changelog:** docs: record the #70 follow-up hardening ([#73](https://github.com/thordin9/apotheke/pull/73))
- **release:** test: check the whole upgrade note leads the notes ([#73](https://github.com/thordin9/apotheke/pull/73))
- **qa:** docs: add the v2.6.2 release QA report ([#73](https://github.com/thordin9/apotheke/pull/73))
- **qa:** docs: add the #73 hardening follow-ups QA report ([#73](https://github.com/thordin9/apotheke/pull/73))
- **docker:** hardening (S-M1, #70 N-2): the ImageMagick policy check also fails unless delegates and `@file` paths have no rights and the time limit is 60 s or less. ([#73](https://github.com/thordin9/apotheke/pull/73))
- **deploy:** docs: health checks need an allowed host name (#70 L-2), plus the restore check under nginx, the container host name, the sign-in session, TLS proxies and the README Host row (#70 N-3 to N-7); a test covers the restore check's 403 (#70 N-1). ([#73](https://github.com/thordin9/apotheke/pull/73))

## [2.6.3] - 2026-10-24

### Fixed

- **auth:** a settings import or a backup restore applies sign-in settings (guest browsing, registration, the registration role, approval, session length, and trust-the-proxy) only for an admin account. Other settings in the file still apply, and skipped keys are named with the other protected keys. A file cannot turn sign-in off. A restore never replaces accounts, roles, or role permissions; a zip that names the users or roles table is refused. The command line still applies the sign-in settings. SEC-M1. ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** only an admin account can grant the admin role, turn sign-in off, or change an existing admin account (role, name, password, approval, or deletion). The Users permission on its own is not enough. Other accounts can still be changed, and the command line can still do all of it. SEC-L1. ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** sign-in attempts are counted per client and account (10 in 15 minutes). `X-Forwarded-For` is used only when Trust the reverse proxy is on and the connection comes from an address in `trusted_proxies` / `TRUSTED_PROXIES`; the client is the rightmost hop that is not itself listed. Registration stays 5 per hour per address. SEC-M2. ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** the `page` query on the catalog and the activity log is limited to 1–100000, so a very large value is an ordinary page. SEC-N2. ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** the Control Panel database line shows host, port, and dbname. A full DSN is parsed the same way as `config_dsn_public_fields()`, and the password is not included. SEC-N3. ([#71](https://github.com/thordin9/apotheke/pull/71))

### Maintenance

- **auth:** docs: document who can change sign-in and how the limit works ([#71](https://github.com/thordin9/apotheke/pull/71))
- **changelog:** docs: record the sign-in hardening ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** docs: limit sign-in import and admin-account changes to admins ([#71](https://github.com/thordin9/apotheke/pull/71))
- **auth:** docs: align the proxy wording with the host checks ([#71](https://github.com/thordin9/apotheke/pull/71))
- **changelog:** docs: keep the #71 notes out of v2.6.2 ([#71](https://github.com/thordin9/apotheke/pull/71))
- **qa:** docs: add the #71 security hardening QA report ([#71](https://github.com/thordin9/apotheke/pull/71))

## [2.6.2] - 2026-10-24

**Upgrade note:** Apotheke now answers only to IP addresses, `localhost`, the machine's own host name, the host of
`PUBLIC_URL` and the names in the new `ALLOWED_HOSTS` setting. Any other host name gets *Unknown host name* (421).
If you open the app by a name, for example `http://nas.local:9091` or `https://apotheke.example`, add that name
before you upgrade (comma-separated; ports are ignored):

```
ALLOWED_HOSTS=nas.local,apotheke.example
```

Setting `PUBLIC_URL` to that address works too. Opening the app by its IP address needs no change. Details:
[Host names](https://github.com/thordin9/apotheke/blob/main/docs/deployment-and-operations.md#host-names).

### Fixed

- **security:** hardening (SEC-M3): the app answers only to IP addresses, `localhost`, its own host name, `PUBLIC_URL`'s host and the new `ALLOWED_HOSTS` list; other host names get 421, and label links never use an unlisted host. With sign-in off, a change must come from the app's own pages (`Sec-Fetch-Site` or `Origin`); requests without either header still work. If you open the app by a name, add it to `ALLOWED_HOSTS` or set `PUBLIC_URL`. ([#70](https://github.com/thordin9/apotheke/pull/70))
- **security:** hardening (SEC-L2, SEC-L3): a session starts only when a page with a form is shown or someone signs in, so 404s, icons, health checks and redirects leave no session file. The session cookie is `Secure` when `PUBLIC_URL` is `https://` and the request uses that host. ([#70](https://github.com/thordin9/apotheke/pull/70))
- **docker:** hardening (SEC-L2): the image accepts a restore upload larger than 22 MB only from a signed-in user with the Backup permission, or while sign-in is off; nginx checks this before it reads the body. ([#70](https://github.com/thordin9/apotheke/pull/70))

### Maintenance

- **autofill:** test: post a JPEG that exactly fills the cap ([#62](https://github.com/thordin9/apotheke/pull/62))
- **release:** ci: reject an Unreleased bullet with no PR link ([#62](https://github.com/thordin9/apotheke/pull/62))
- **qa:** docs: add the v2.6.1 release QA report ([#62](https://github.com/thordin9/apotheke/pull/62))
- **qa:** docs: add the #62 v2.6.1 follow-ups QA report ([#62](https://github.com/thordin9/apotheke/pull/62))
- **qa:** docs: add QA2 review for #70 security hardening ([#70](https://github.com/thordin9/apotheke/pull/70))
- **docker:** hardening (S-M1, S-L1): the image ships a restrictive ImageMagick policy (size, memory, disk and time limits; text, vector, page and URL coders, delegates and `@file` off), and the build fails if it is not in effect. ([#70](https://github.com/thordin9/apotheke/pull/70))
- **deploy:** docs: describe `trust_proxy`, Host names, the restore limit and the ImageMagick policy, and add HSTS to the Caddy and nginx examples (SEC-L3). ([#70](https://github.com/thordin9/apotheke/pull/70))

- **changelog:** the first upload and the 413 retry are tested with a JPEG that fills the cap, and a handwritten bullet must link its pull request. ([#62](https://github.com/thordin9/apotheke/pull/62))

## [2.6.1] - 2026-10-24

### Fixed

- **release:** a handwritten changelog bullet replaces the generated bullet with the same scope, so the release notes do not list that change twice. ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** an image-limit clamp is logged once per distinct pair of sizes. A later photo, and the rebuild after an HTTP 413, does not log that pair again. The line still has no token. ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** JPEG quality steps down by 10 from the configured quality. 60 is still tried before the long edge shrinks, so a quality of 95 no longer jumps straight to 70. ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** the byte cap is applied to the JPEG the service limits. The multipart wrapper, about 320 bytes, is not counted. ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** a limit that would display as 0 MiB is shown in KB (1000 bytes is 1 KB). ([#60](https://github.com/thordin9/apotheke/pull/60))

### Maintenance

- **autofill:** docs: say the clamp warning is once per pair ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** docs: describe the ten-point quality step ([#60](https://github.com/thordin9/apotheke/pull/60))
- **autofill:** docs: say a tiny limit is shown in KB ([#60](https://github.com/thordin9/apotheke/pull/60))
- **qa:** docs: add the #60 max image size nits QA report ([#60](https://github.com/thordin9/apotheke/pull/60))
- **qa:** docs: add the v2.6.0 release QA report ([#60](https://github.com/thordin9/apotheke/pull/60))

## [2.6.0] - 2026-10-23

### Added

- **autofill:** add a configurable maximum image size ([#59](https://github.com/thordin9/apotheke/pull/59))
- **autofill:** Control Panel → AI autofill has **Maximum image size (bytes)** (`autofill_max_image_bytes`, default `auto`). `auto` uses `max_image_bytes` from the service’s `GET /v1/info`, or 983040 bytes when that answer is unavailable. An explicit value must be a whole number from 65536 to 10485760. There is no environment override. An explicit value is capped to `enforced_image_bytes` when that field is present, otherwise to `max_image_bytes`, and used as stored only when neither is known. A lower cap logs a warning and never the token. An HTTP 413 refetches `/v1/info` once, clamps again, and retries the upload once. The copy is a hard maximum: JPEG quality steps down, then the long edge, until it fits. The stored photo stays byte-identical. ([#59](https://github.com/thordin9/apotheke/pull/59))

### Fixed

- **release:** build the release body from the full section ([#59](https://github.com/thordin9/apotheke/pull/59))
- **release:** keep blank lines inside changelog notes ([#59](https://github.com/thordin9/apotheke/pull/59))
- **bootstrap:** write the data-dir error to stderr ([#59](https://github.com/thordin9/apotheke/pull/59))
- **bootstrap:** avoid the STDERR constant under php-fpm ([#59](https://github.com/thordin9/apotheke/pull/59))
- **autofill:** cap an explicit size to the service limit ([#59](https://github.com/thordin9/apotheke/pull/59))
- **db:** while the database is down, every route (CSS, the manifest and /login included) returns 500. The log has only the sanitized connect line. The database user, the password and the DSN are not logged. ([#59](https://github.com/thordin9/apotheke/pull/59))

### Maintenance

- **e2e:** test: update autofill settings screenshot baselines ([#59](https://github.com/thordin9/apotheke/pull/59))
- **db:** docs: record the 500 while the database is down ([#59](https://github.com/thordin9/apotheke/pull/59))
- **changelog:** docs: drop the duplicate docker and db lines ([#59](https://github.com/thordin9/apotheke/pull/59))
- **qa:** docs: add the v2.5.8 release QA report ([#59](https://github.com/thordin9/apotheke/pull/59))
- **release:** test: run apply and check the notes file ([#59](https://github.com/thordin9/apotheke/pull/59))
- **changelog:** docs: link the image-size note to #59 ([#59](https://github.com/thordin9/apotheke/pull/59))
- **autofill:** docs: say a blank image size saves as auto ([#59](https://github.com/thordin9/apotheke/pull/59))
- **e2e:** test: update autofill settings baselines ([#59](https://github.com/thordin9/apotheke/pull/59))
- **qa:** docs: add the v2.6.0 max image size QA report ([#59](https://github.com/thordin9/apotheke/pull/59))

## [2.5.x] - 2026-10-20 to 2026-10-23

Releases 2.5.0 to 2.5.8.

- **autofill:** a separate stripped JPEG is sent, and the uploaded camera file is kept byte for byte under `originals/`, outside the web root. A 503 is retried only when `Retry-After` is at most 60 s.
- **photos:** the Docker image decodes HEIC. Imagick flattens transparency onto white, converts CMYK to sRGB and writes the analysis JPEG with 4:2:0 subsampling. The image builds Imagick from pinned source tarballs.
- **qr:** function JIT is turned off before the QR encoder loads; on PHP 8.2 to 8.4 outside Docker it could write wrong codes.
- **db:** `CREATE SCHEMA` is skipped when `DB_SCHEMA` already exists, and `bin/migrate.php` prints only the driver, host, port, dbname and `DB_SCHEMA`.
- **db:** seed, backup, restore and settings exit 1 when they can't connect to the database, and print only those same fields.
- **docker:** the Docker image now builds on PHP 8.3.36, the PHP security release of 21 Oct 2026. Exception traces omit function arguments.
- **ci:** a gitleaks secret scan and job timeouts.

## [2.4.x] - 2026-10-19

Releases 2.4.0 and 2.4.1.

- **autofill:** photos are analyzed on demand with an *Analyze* button; *Analyze photos automatically* is off on every install (migration 0025). Model and image size dropdowns come from `/v1/info`. One analysis runs at a time, and a second one only fills empty fields.

## [2.3.x] - 2026-10-17 to 2026-10-18

Releases 2.3.0 to 2.3.2.

- **autofill:** a progress estimate, one retry when the service is busy or warming up, and *Test with a photo*. An untouched default category can be replaced by a suggestion.
- **seed:** new installs also get the Clothing, Shoes, Books and Lighting categories.

## [2.2.0] - 2026-10-14

- **autofill:** optional AI autofill: Control Panel settings, *Test connection*, and name, category, tags and notes suggested from a photo. The stored token is sent only to the saved address and is cleared when the address changes.

## [2.1.0] - 2026-10-14

- **ui:** phone overview, two-column form, safe Save bar, one Edit button with Delete in a danger zone.
- **export:** CSV cells are protected against formula injection.
- **auth:** `Secure` cookie behind trusted proxies and a configurable sign-in lifetime.

## [2.0.0] - 2026-10-13

- **docker:** breaking: the image serves on port 9091 (it was 80 inside the container, and compose published 8080).

## [1.2.0] - 2026-10-13

- Location stocktakes, user accounts with roles (custom roles, guest browsing, self-registration), ranked full-text search with highlights and typo tolerance, CSV import with column mapping and a dry run, and in-browser photo crop, rotate and reordering.

## [1.1.x] - 2026-10-13

Releases 1.1.0 and 1.1.1.

- Markdown notes and attachments, bulk QR label printing, favourites and recently viewed, merging duplicates and undo.
- Automatic SemVer releases with this changelog and the README coverage table.

## [1.0.0] - 2026-10-13

- First versioned release, everything merged before automatic releases started: the mobile-first UI with dark mode, SQLite and PostgreSQL with versioned migrations, photos, tags and custom fields, QR labels, backup and restore, history, low-stock alerts, bulk actions, lending, nested locations, expiry dates, value totals, saved filters, barcodes, the Control Panel and the nginx + php-fpm Docker image.

[Unreleased]: https://github.com/thordin9/apotheke/compare/v2.6.19...HEAD
[2.6.19]: https://github.com/thordin9/apotheke/compare/v2.6.18...v2.6.19
[2.6.18]: https://github.com/thordin9/apotheke/compare/v2.6.17...v2.6.18
[2.6.17]: https://github.com/thordin9/apotheke/compare/v2.6.16...v2.6.17
[2.6.16]: https://github.com/thordin9/apotheke/compare/v2.6.15...v2.6.16
[2.6.15]: https://github.com/thordin9/apotheke/compare/v2.6.14...v2.6.15
[2.6.14]: https://github.com/thordin9/apotheke/compare/v2.6.13...v2.6.14
[2.6.13]: https://github.com/thordin9/apotheke/compare/v2.6.12...v2.6.13
[2.6.12]: https://github.com/thordin9/apotheke/compare/v2.6.11...v2.6.12
[2.6.11]: https://github.com/thordin9/apotheke/compare/v2.6.10...v2.6.11
[2.6.10]: https://github.com/thordin9/apotheke/compare/v2.6.9...v2.6.10
[2.6.9]: https://github.com/thordin9/apotheke/compare/v2.6.8...v2.6.9
[2.6.8]: https://github.com/thordin9/apotheke/compare/v2.6.7...v2.6.8
[2.6.7]: https://github.com/thordin9/apotheke/compare/v2.6.6...v2.6.7
[2.6.6]: https://github.com/thordin9/apotheke/compare/v2.6.5...v2.6.6
[2.6.5]: https://github.com/thordin9/apotheke/compare/v2.6.4...v2.6.5
[2.6.4]: https://github.com/thordin9/apotheke/compare/v2.6.3...v2.6.4
[2.6.3]: https://github.com/thordin9/apotheke/compare/v2.6.2...v2.6.3
[2.6.2]: https://github.com/thordin9/apotheke/compare/v2.6.1...v2.6.2
[2.6.1]: https://github.com/thordin9/apotheke/compare/v2.6.0...v2.6.1
[2.6.0]: https://github.com/thordin9/apotheke/releases/tag/v2.6.0
