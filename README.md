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

PHP 8.4 and **CodeIgniter 4** (pinned Composer dependencies) with PDO (SQLite or PostgreSQL) and GD, plus Imagick in
the Docker image for HEIC photos. No JavaScript build step and no CDNs: it works fully offline on your LAN.

**3.0.0** is the CodeIgniter 4 app on `main`: no schema change, Docker-only, and one re-login at upgrade
([upgrading to 3.0](docs/upgrade-3.0.md)). See [ADR-001](docs/adr/001-codeigniter4.md) and the
[Feature 12 plan](docs/plans/feature12-ci4/PLAN.md). After 3.0.0, 2.5.x gets security fixes on `release/2.5` for
90 days; the 2.x app is kept on the branch `old`.

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

## Documentation

The manual lives in [`docs/`](docs/index.md):
[getting started](docs/getting-started.md) · [configuration](docs/configuration.md) ·
[Control Panel](docs/control-panel.md) · [user guide](docs/user-guide.md) · [AI autofill](docs/autofill.md) ·
[deployment and operations](docs/deployment-and-operations.md) (backups, upgrades, reverse proxies, security) ·
[upgrading to 3.0](docs/upgrade-3.0.md) ·
[architecture](docs/architecture.md) · [database](docs/database.md) · [testing](docs/testing.md) · [CI](docs/ci.md) ·
[releasing](docs/releasing.md) · [contributing](docs/contributing.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).
Agents: see [`AGENTS.md`](AGENTS.md).

## Coverage

<!-- coverage:start -->
| Area | PHPUnit (unit) | HTTP integration | Combined |
|---|---:|---:|---:|
| Front controller & routes | – | 97.2 % | 97.2 % |
| Items, search, bulk, lending | 88.9 % | 90.3 % | 95.4 % |
| Favourites, merge, undo | 80.9 % | 99.5 % | 99.5 % |
| Stocktake | 46.2 % | 98.2 % | 99.5 % |
| Users & roles | 95.3 % | 89.5 % | 97.3 % |
| Full-text search | 89.0 % | 87.7 % | 90.8 % |
| CSV import | 62.5 % | 96.5 % | 99.3 % |
| Photos | 59.6 % | 87.2 % | 87.2 % |
| Notes & attachments | 88.2 % | 90.7 % | 96.3 % |
| Views (HTML) | 12.7 % | 99.4 % | 99.3 % |
| Control Panel settings | 73.2 % | 88.2 % | 92.9 % |
| Backup & restore | 94.1 % | 91.9 % | 98.4 % |
| Database, migrations, legacy | 81.7 % | 81.5 % | 91.9 % |
| Bootstrap, config, QR, seed | 73.6 % | 75.6 % | 91.0 % |
| **All PHP** (7736 lines) | **67.5 %** | **89.0 %** | **95.4 %** |

| Playwright project | Functional tests | Visual tests |
|---|---:|---:|
| `chromium-pixel` | 139 | 22 |
| `chromium-pixel-dark` | 2 | 22 |
| `desktop-chromium` | 139 | 22 |
| `desktop-chromium-dark` | 2 | 22 |
| `pgsql-smoke` | 33 | 0 |
| `webkit-iphone-se` | 139 | 22 |
| `webkit-iphone-se-dark` | 2 | 22 |
| `webkit-iphone13` | 139 | 22 |
| `webkit-iphone13-dark` | 2 | 22 |
| **Total** | **597** | **176** |

<sub>Line coverage from pcov: PHPUnit unit suite, the HTTP integration suite (every `php -S` request of `tests/integration.php`, collected by `tests/coverage/prepend.php`) and both merged with phpcov, SQLite driver. Regenerate with `npm run coverage:readme`; the release workflow refreshes it from the CI artifact in every release commit.</sub>
<!-- coverage:end -->

## Contributing

Pull requests are welcome. Commits follow [Conventional Commits](https://www.conventionalcommits.org/) (subject ≤ 72
characters, checked in CI); see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE) © 2026 Stathis Papandreou
