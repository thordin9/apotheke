# Self-host with Docker

Apotheke v4 on your own machine, in two containers. This is the walkthrough plan §11 asks for: it is
meant to be followed literally, by someone who has not read the plan, on a fresh machine.

Read the section **What is not exercised here** before you trust this stack with data you care about.

## What you get

| File | What it starts |
|---|---|
| `docker-compose.yml` | `apotheke` + `rqlite`: the app, and a database beside it that can snapshot and replicate |
| `docker-compose.sqlite.yml` | `apotheke` alone, on local SQLite — one process, one volume |
| `.env.example` | every setting, with a safe default and a comment |

**Where these files live right now (plan D16).** Until the v4 cutover they are `v4/docker-compose.yml`,
`v4/docker-compose.sqlite.yml` and `v4/.env.example`: the repository root still ships v3's
`docker-compose.yml` and `.env.example`, which are the files today's v3 installs run. Nothing at the
root is touched before P7 — pulling this branch must never break a working v3 install. The commands
below assume you are in `v4/`.

**What ships at release is the root-level path.** P7's cutover moves these three files to the
repository root in one PR, leaving v3's stack beside them for exactly one release as
`docker-compose.v3.yml`, so an operator can roll back by pointing at that file. From then on the
commands below run from the repository root and `scripts/selfhost-smoke.sh` takes
`docker-compose.yml` as its argument — the same script, unchanged.

Both files publish exactly one port, `9091`, for the app. The database port is **not** published: the
app reaches rqlite at `http://rqlite:4001` on the compose network.

## Before you start

- Docker Engine 24+ with Compose v2 (`docker compose version` must work — the `docker-compose`
  script is not supported by these files).
- About 2 GB of disk: the skeleton image builds the Node workspace, `node_modules` included.
- A password for the first `admin` account, at least 8 characters.

Nothing else. No database to prepare, no image to pull by hand.

## Start it

```sh
cd v4
cp .env.example .env

# 1. Pick the first admin's password. Without this the seed phase refuses and /readyz stays 503.
#    Every other value in .env has a working default.
$EDITOR .env          # set APP_PASSWORD=...            (and APP_KEY=..., see below)

# 2. Start the default stack (app + rqlite).
docker compose up -d

# 3. Watch it come up. First boot runs the whole migration chain and the seed phase.
docker compose ps
curl -s http://localhost:9091/healthz; echo
```

`/healthz` answers `{"ok":true,"driver":"rqlite"}` as soon as the process is up — it does not touch the
database, so it also answers while rqlite is still electing itself. The one that proves the install
is ready is:

```sh
curl -s http://localhost:9091/readyz; echo
# {"ok":true,"schema":"0036","seed":"done","driver":"rqlite"}
```

`/readyz` returning `503` with `"title":"seed.pending"` means the boot is still working; with
`"title":"seed.refused"` it will not finish until you fix what the `detail` names (usually a too-short
`APP_PASSWORD`).

**This is the surface so far.** `http://localhost:9091/` answers `501` `route.not_implemented`
problem+json — the router and the filter chain landed in S0.5, and the remaining pages are phase 2 —
and most other page routes answer either a refusal or the same 501. What answers today is `/login`
(it renders the sign-in form, and `POST /login` signs the seeded `admin` in with the `APP_PASSWORD`
set above), `/settings/security` and that page's users and roles POSTs. Item 6 below is the list of
what answers and what does not.

To set `APP_KEY` (encrypts stored credentials at rest), generate one and paste it in:

```sh
openssl rand -base64 32
```

Leaving it empty works; stored secrets are then kept unencrypted. Changing it later makes existing
encrypted values unreadable — decide before you store any.

## The single-process variant

```sh
docker compose -f docker-compose.sqlite.yml up -d
```

Same app, same port, same first-boot behaviour; the installation's SQLite database lives inside the
`apotheke-data` volume. This is the option for one node on one disk. You lose replication and
snapshots, and you gain one less process to watch. Both files use their own compose project name, so
you can keep both around — but they both want port 9091, so stop one before starting the other.

## Healthchecks, and why they are what they are

- **The app is probed on `/healthz`, never `/readyz`.** `/readyz` moves behind sign-in in phase 1, and
  a `401` read as "unhealthy" would restart-loop a perfectly good container. `/healthz` means "the
  process is up", which is what a container healthcheck is for.
- **rqlite is probed on rqlite's own `/readyz`.** That endpoint needs credentials as soon as the node
  is started with `-auth`, so the healthcheck passes `RQLITE_AUTH` from `.env`; with `RQLITE_AUTH`
  empty it degrades to a TCP connect on 4001. A `401` is never treated as healthy.
- **`depends_on: service_healthy` only orders the cold start.** It does not survive rqlite restarting
  while the app is running. What makes that case work is the app's own retry policy: a request that
  finds the database unreachable is retried by `/readyz` with a one-second cooldown, one attempt at a
  time, and the driver retries reads and keyed writes. Do not add restart-order tricks — restart
  rqlite whenever you like:

  ```sh
  docker compose restart rqlite
  curl -s -o /dev/null -w '%{http_code}\n' http://localhost:9091/healthz   # 200 throughout
  ```

## Logs

```sh
docker compose logs -f apotheke
docker compose logs -f rqlite
```

## Upgrade

```sh
docker compose pull      # rqlite, when a new tag is pinned
docker compose build     # the app image, while it is the skeleton
docker compose up -d
```

Migrations run on boot, inside the installation's lock, before the app serves. A database written by a
**newer** image is refused with an explicit `/readyz` answer (`"title":"schema.newer"`) rather than
half-migrated. **Downgrade is "restore the backup"** — that is the whole story, and it is why the
backup below matters more than the upgrade.

## Backup and restore

The backup of record is the app's own ZIP export, from the UI. It is the same format v3 uses, and it
is the only thing restore accepts. There is no UI until phase 2, so the ZIP export is not reachable
from this stack yet — see item 6.

For rqlite operators, a node-level extra (not a second product format):

```sh
docker compose exec rqlite rqlite -h 127.0.0.1 -p 4001 backup /rqlite/file/backup.db
```

A raw volume snapshot (`docker run --rm -v apotheke_rqlite-data:/data:ro ...`) works while the stack is
stopped. Neither replaces the ZIP: restoring from them is a database operation, not an Apotheke one.

Under `docker-compose.sqlite.yml` the volume **is** the installation, so back up the ZIP export, not
just the volume.

## Troubleshooting

| Symptom | What it means |
|---|---|
| `/healthz` never answers, `docker compose ps` shows `starting` | The app is still booting (Node + wrangler + `npm ci` output in `logs`). First start is the slow one. |
| `/readyz` 503 `seed.pending` | Boot in progress, or the seed phase failed and is reporting below. Read `detail`. |
| `/readyz` 503 `seed.refused` | `APP_PASSWORD` is too short (or contains a NUL). Fix `.env`, `docker compose up -d` again. |
| `/readyz` 503 `database` | The app cannot reach rqlite. `docker compose logs rqlite`; a fresh node that has not elected a leader yet answers `503` and says so. |
| rqlite container unhealthy, app fine | Almost always `-auth` credentials: `RQLITE_AUTH` in `.env` must be the same single value the app and the node both read. A mismatched password makes `/readyz` answer `401`, which the healthcheck reads as unhealthy — correctly. |
| `port is already allocated` | Another stack or a v3 install owns 9091. Change the published port in the compose file, or stop the other one. |
| Everything works but the browser shows the wrong links | Set `PUBLIC_URL` to the address you actually use, no trailing slash. |

`docker compose config` is safe to paste into an issue **only from a checkout without `.env`** — the
compose files hold no secrets, but the command interpolates the `.env` that is present.

## What is not exercised here

Stated plainly, because a self-host promise that hides its edges is worse than no promise:

1. **The image is a skeleton, not the release image.** `docker/Dockerfile.stub` installs the workspace
   and runs the real Worker under `wrangler dev`: real code, real migrations, real seed, real
   `/healthz` and `/readyz`. What it is not: a hardened production container (it runs as root, ships
   dev tooling, and starts slower than a built bundle will). The shipping image is P6.
2. **No TLS between the app and rqlite.** Over the compose network the driver speaks plain HTTP. The
   driver supports `https://`, but a self-signed certificate would need runtime trust and there is no
   insecure switch; that work is G6, not this skeleton.
3. **The database port is not published, and that is the security model here.** If you publish 4001
   for debugging, set `RQLITE_AUTH` first. An unauthenticated published rqlite is readable by anything
   that can reach the host.
4. **No hosted-D1 coverage.** These files are the self-host path only. Hosted installations run on D1
   (plan §10.4); the emulated binding is tested in CI, hosted D1 is phase 1.
5. **One volume for rqlite, not two.** rqlite v10.7.4 keeps the raft log and the SQLite database
   inside one data directory (`raft.db`, `raft/`, `db.sqlite`) and has no flag to split them, so this
   stack mounts one volume at `/rqlite/file` with `DATA_DIR=/rqlite/file/data`. Plan §11's two-volume
   row was **corrected to match** in revision 13 (**D17**): the plan and these files now agree.
6. **A working sign-in, and a UI only as far as `/login` and `/claim`.** `/login` renders the page PHP
   renders (S1.1) and `POST /login` signs in (S1.2); the app's own error pages are back for a
   page-level 404/405. With a session cookie and the consent cookie, `POST /login` answers `303` back
   to `/`, `GET /logout` answers `303` (to `/login`), and `POST /consent` records the notice's answer.
   Without consent a write is refused `403` `consent_required` and no session cookie is set — that
   gate is the contract (D20), not a bug to work around. Every other page route answers `501`, or
   `303` to `/login` where the route needs a session.
   `/claim` is the hosted first-admin link: `404` unless `APOTHEKE_HOSTED` is on — this walkthrough
   does not set it, so expect the 404 — and with it a valid token creates the admin once (S1.2b).
   Since S1.3 the Control Panel's Users & sign-in page answers too: sign in as the seeded admin, then
   `GET /settings/security` is `200` and lists the accounts and the roles editor. Its users half
   works — `POST /settings/users` (and `/settings/users/{id}/{role,password,rename,approve,delete}`)
   adds and changes accounts and answers `303` to `/settings/security?done=…#users`, or re-renders the
   page with v3's own message and status (`409` for the last admin, `422` for a taken name, `403` for
   the admin-only guards). Its roles half works too: `POST /settings/roles` (and
   `/settings/roles/{key}`, `/settings/roles/{key}/delete`) creates a role, saves its name and
   permissions, and deletes a custom role into the one its accounts move to — `RoleAdmin`'s rules, the
   last-manager check included, so removing the last way to manage users is refused `409`. The
   settings form (`POST /settings/security`, `…/reset`, `POST /settings/password`) still answers
   `501`.
   What does answer: `/healthz`, `/readyz`, `/assets/…` (the bundle), `/login` (GET/HEAD `200`, POST
   `303`), `/logout`, `/consent`, `/claim` (hosted), `/settings/security` (`200` for a role with
   `users`, `303` to `/login` otherwise) with its users and roles POSTs, the page error pages, the `/api` 404
   (`not_found`, problem+json) and 405s with `Allow`. `/readyz`'s `"seed":"done"` therefore means the
   install is complete; the seeded `admin` can sign in with the `APP_PASSWORD` you set above.

## Where the automation lives

`scripts/selfhost-smoke.sh` is the same walkthrough in assertions, run by CI against **both** compose
files:

```sh
# from the repository root; the compose file is the argument
scripts/selfhost-smoke.sh v4/docker-compose.yml
scripts/selfhost-smoke.sh v4/docker-compose.sqlite.yml
```

It fails — it does not skip — when the Docker CLI is missing, because a smoke test that skips is how
a green job ends up testing nothing.
