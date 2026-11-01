<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/apotheke-logo-dark.svg">
    <img src="docs/assets/apotheke-logo.svg" alt="Apotheke" width="330">
  </picture>
</p>

# Apotheke

[![CI](https://github.com/thordin9/apotheke/actions/workflows/ci.yml/badge.svg)](https://github.com/thordin9/apotheke/actions/workflows/ci.yml)

*The storehouse for your stuff.* (Greek **ἀποθήκη**, *apothḗkē*: a place where things are put away for safekeeping.)

A small, fast, self-hosted web app for cataloguing your belongings: snap a photo on your phone, name the item, pick
where it lives ("box 1023") and what it is ("Tools"), set a quantity, done. Then find anything again with instant search.

PHP 8.4 and **CodeIgniter 4** (pinned Composer dependencies) with PDO (SQLite 3.38+ or PostgreSQL) and GD, plus Imagick in
the Docker image for HEIC photos. No JavaScript build step and no CDNs: it works fully offline on your LAN.

**3.0.0** is the CodeIgniter 4 app on `main`: no schema change, Docker-only, and one re-login at upgrade
([upgrading to 3.0](docs/upgrade-3.0.md)). See [ADR-001](docs/adr/001-codeigniter4.md) and the
[Feature 12 plan](docs/plans/feature12-ci4/PLAN.md). For 90 days after 3.0.0, the 2.x line gets security fixes
only, as 2.6.x releases from the branch `old` (the former `main`, last release v2.6.26).

## Features

* Mobile-first UI with dark mode; every screen works one-handed on a phone.
* Items with up to 12 photos (crop, rotate, reorder), tags, notes in Markdown, attachments, purchase price and date,
  serial number, barcode, expiry or warranty date.
* Nested locations and categories, instant ranked full-text search (typo-tolerant, Greek included), a filter sheet
  and saved filters.
* Overview statistics, low-stock alerts, lending, bulk actions, duplicating, merging duplicates, undo, stocktakes and
  an activity history.
* QR labels, CSV import and export, and portable backup and restore across SQLite and PostgreSQL.
* Optional users with roles and permissions, guest browsing and self-registration.
* A Control Panel for branding, formats, defaults and limits, and optional
  [AI autofill](docs/autofill.md) of name, category, tags and notes from a photo.

Every feature is described in the [user guide](docs/user-guide.md).

| List & overview | Filter sheet | Item (dark) | Desktop |
|---|---|---|---|
| ![List](docs/screenshots/list-light.png) | ![Filter sheet](docs/screenshots/filters-light.png) | ![Item](docs/screenshots/item-dark.png) | ![Desktop](docs/screenshots/desktop-light.png) |

## Quick start (Docker)

```bash
git clone https://github.com/thordin9/apotheke.git && cd apotheke
echo 'APP_PASSWORD=change-me' > .env    # pick your own (8+ characters): the password of the account admin
docker compose up -d --build
```

The app then runs on `http://127.0.0.1:9091`. Docker installs are private by default: compose publishes the port on
`127.0.0.1` only, and the container refuses to start with sign-in off unless `ALLOW_NO_LOGIN=1` is set. To open it from
your phone, to encrypt stored secrets with `APP_KEY`, to use PostgreSQL or to run without Docker, see
[Getting started](docs/getting-started.md).

Docker is the recommended way to self-host. To run it on your own Cloudflare account instead, see
[self-hosting on Cloudflare](docs/self-host-cloudflare.md): today only the front end is usable there; the API stays
stateless until its D1 and R2 storage lands (P4).

## Documentation

The manual lives in [`docs/`](docs/index.md):
[getting started](docs/getting-started.md) ·
[self-hosting on Cloudflare](docs/self-host-cloudflare.md) · [configuration](docs/configuration.md) ·
[Control Panel](docs/control-panel.md) · [user guide](docs/user-guide.md) · [AI autofill](docs/autofill.md) ·
[deployment and operations](docs/deployment-and-operations.md) (backups, upgrades, reverse proxies, security) ·
[upgrading to 3.0](docs/upgrade-3.0.md) ·
[architecture](docs/architecture.md) · [database](docs/database.md) · [testing](docs/testing.md) · [CI](docs/ci.md) ·
[releasing](docs/releasing.md) · [contributing](docs/contributing.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).
Agents: see [`AGENTS.md`](AGENTS.md).

## Coverage

<!-- coverage:start -->
| Area | Module suite | Root suite (with HTTP) | Combined |
|---|---:|---:|---:|
| Front controller, config & routes | 81.2 % | 88.1 % | 88.9 % |
| Items, search, bulk, lending | 29.6 % | 76.3 % | 82.2 % |
| Favourites, undo, history | 10.6 % | 61.6 % | 64.8 % |
| Stocktake & labels | 46.7 % | 95.6 % | 95.8 % |
| Users, roles & sign-in | 58.9 % | 78.9 % | 82.2 % |
| CSV import & export | 57.1 % | 84.4 % | 95.9 % |
| Photos & autofill | 68.2 % | 14.3 % | 69.7 % |
| Notes & layout | 88.3 % | 75.2 % | 94.8 % |
| Settings & Control Panel | 53.3 % | 48.5 % | 69.1 % |
| Backup & restore | 57.7 % | 75.6 % | 82.1 % |
| Database & migrations | 71.8 % | 73.2 % | 74.4 % |
| Core & dev server | 78.2 % | 69.4 % | 94.1 % |
| **All PHP** (16697 lines) | **62.2 %** | **72.4 %** | **84.2 %** |

| Playwright project | Functional tests | Visual tests |
|---|---:|---:|
| `chromium-pixel` | 142 | 22 |
| `chromium-pixel-dark` | 2 | 22 |
| `desktop-chromium` | 142 | 22 |
| `desktop-chromium-dark` | 2 | 22 |
| `pgsql-smoke` | 34 | 0 |
| `webkit-iphone-se` | 142 | 22 |
| `webkit-iphone-se-dark` | 2 | 22 |
| `webkit-iphone13` | 142 | 22 |
| `webkit-iphone13-dark` | 2 | 22 |
| **Total** | **610** | **176** |

<sub>Line coverage from pcov: the module suite (`tests/Modules`), the root suite (`tests/Unit`, with every `php -S` request its servers answer, collected by `tests/coverage/prepend.php`) and both merged with phpcov, SQLite driver. Regenerate with `npm run coverage:readme`; the release workflow refreshes it from the CI artifact in every release commit.</sub>
<!-- coverage:end -->

## Contributing

Pull requests are welcome. Commits follow [Conventional Commits](https://www.conventionalcommits.org/) (subject ≤ 72
characters, checked in CI); see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE) © 2026 Stathis Papandreou

The logo, wordmark and mascot artwork is not covered by that licence; see [frontend/brand/NOTICE](frontend/brand/NOTICE).
