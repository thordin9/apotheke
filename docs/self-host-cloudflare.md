# Self-hosting on your own Cloudflare account

**Docker is the recommended way to self-host Apotheke** ([Getting started](getting-started.md)). This page is for
running a copy on **your own** Cloudflare account instead. It is a manual install with `wrangler`, and today only part
of it is usable: read [What works today](#what-works-today) first.

The hosted getapotheke.com deploy is a different thing: it is described in
[Deployment and operations: Cloudflare deploys](deployment-and-operations.md#cloudflare-deploys-getapothekecom), and its
workflows only deploy to the project's own account (see [The project's CI is not for your account](#the-projects-ci-is-not-for-your-account)).

Everything in angle brackets is a placeholder you choose: `<your-frontend-worker>`, `<your-api-worker>`,
`<your-account-id>`, `<private-dir>`. `example.com` stands for your own domain (a zone on your account), with the
front end on `www.example.com` and the API on `api.example.com` in the examples.

* [What it deploys](#what-it-deploys)
* [What works today](#what-works-today)
* [Prerequisites](#prerequisites)
* [An API token for your account](#an-api-token-for-your-account)
* [Names: what is hard-coded today](#names-what-is-hard-coded-today)
* [Deploy the front end](#deploy-the-front-end)
* [The API (pending P4: D1 + R2)](#the-api-pending-p4-d1--r2)
* [Custom domains](#custom-domains)
* [Upgrading and rolling back](#upgrading-and-rolling-back)
* [Backups](#backups)
* [The project's CI is not for your account](#the-projects-ci-is-not-for-your-account)

## What it deploys

| Part | Code | Cloudflare products | Config |
|---|---|---|---|
| Front end | `edge/app/` (routing and headers) + `frontend/dist` (the landing page and the SPA under `/app/`) | a Worker with static assets | [`edge/app/wrangler.jsonc`](../edge/app/wrangler.jsonc) |
| API | `edge/` (Worker + Container Durable Object) + [`Dockerfile.frankenphp`](../Dockerfile.frankenphp) (the FrankenPHP image) | a Worker, a Durable Object and a Container (instance `basic`, at most 1 instance, sleeps after 15 minutes) | [`edge/wrangler.jsonc`](../edge/wrangler.jsonc) |
| Storage (**pending P4**) | storage adapters, not written yet | D1 for the database, R2 for photos and files | not in the config yet |

## What works today

* **Front end: yes.** The landing page and the SPA placeholder under `/app/` deploy and work on your own Worker. The
  placeholder does not sign in yet (sign-in stays off until the SPA passes the API origin), so it needs no API.
* **API: pending P4 (D1 + R2). Not for real data.** The Worker and its container deploy, but a Cloudflare Containers
  instance has **no persistent disk**: `/data` starts empty on every cold start (after 15 idle minutes, a deploy or a
  restart). Each start is a new install, the `admin` account is created again from `APP_PASSWORD`, and everything
  saved before is gone. Use it only to try the API out.
* **Storage: pending P4 (D1 + R2).** Nothing to set up yet. When it lands this page gets the D1 and R2 steps.

Until then, run Apotheke with Docker for anything you want to keep.

## Prerequisites

* **A Cloudflare account on the Workers Paid plan.** Containers are not available on the Free plan; their included
  usage is part of Workers Paid, and usage above it is billed
  ([Containers pricing](https://developers.cloudflare.com/containers/pricing/)). The front end alone also works on
  the Free plan.
* **Your account id.** In the Cloudflare dashboard it is shown on the account home and on *Workers & Pages* (Account
  details). Keep it in the environment as `CLOUDFLARE_ACCOUNT_ID`; it is not a secret, but don't commit it.
* **Node.js 22 or later and npm** (`edge/package.json` asks for Node 22+; the project's CI uses 22).
* **wrangler 4.156.0**, the version `edge/package.json` pins. Don't install it globally: `npm ci --ignore-scripts` in
  `edge/` installs that exact version, and `npx --no-install wrangler` uses it (also from `edge/app/`).
* **Docker, running**, for the API only: `wrangler deploy` builds the container image from `Dockerfile.frankenphp`
  with your local Docker and pushes it to your account's registry. The image must run on `linux/amd64`
  ([Containers: get started](https://developers.cloudflare.com/containers/get-started/)).
* **A domain on your account (a zone)** if you want custom domains. Without one, the configs have `workers_dev` and
  `preview_urls` off, so a Worker has no public URL until you attach a domain.
* A checkout of a release tag (see [Upgrading](#upgrading-and-rolling-back)), with its tags: the front-end build reads
  the latest release tag and its date.

```sh
git clone https://github.com/thordin9/apotheke.git && cd apotheke
git checkout vX.Y.Z           # the release you want, from https://github.com/thordin9/apotheke/releases
(cd edge && npm ci --ignore-scripts)
```

## An API token for your account

Create your own token (dashboard: *My Profile > API Tokens > Create Token > Custom token*, or an account-owned token
under *Manage Account > Account API Tokens*). Give it only what you need, scoped to **your account** and, for zone
permissions, to **your zone** only. Permission names as in the dashboard (the API reference spells *Edit* as
*Write*; see [API token permissions](https://developers.cloudflare.com/fundamentals/api/reference/permissions/)):

| Permission | When | Why |
|---|---|---|
| **Account > Workers Scripts > Edit** | always | upload, deploy and roll back both Workers, and set their secrets |
| **Account > Containers > Edit** | API | push the container image and create or update the container application |
| **Zone > Workers Routes > Edit**, on your zone | custom domains or routes | adding, changing or removing a Custom Domain or route needs it for every affected zone ([Workers roles and permissions](https://developers.cloudflare.com/workers/authorization/workers/)) |
| **Zone > DNS > Edit**, on your zone | routes only, if you create their DNS record with the token | a route needs a proxied DNS record for its host; a Custom Domain creates its own DNS record and certificate, so it doesn't need this |
| **Account > Account Settings > Read** | only if wrangler asks | with `CLOUDFLARE_ACCOUNT_ID` set, wrangler doesn't have to look the account up |
| **Account > D1 > Edit**, **Account > Workers R2 Storage > Edit** | **pending P4** | create the database and bucket, run migrations and exports. Deploying a Worker that is only *bound* to them needs no extra permission ([Bindings](https://developers.cloudflare.com/workers/authorization/workers/#bindings)) |
| Account > Workers Tail > Read | optional | `wrangler tail` (live logs) |

No other permission is needed. In particular the token needs no KV permission (Apotheke uses no KV) and no
*User* permissions.

Cloudflare is moving Workers permissions to roles that can be **scoped to single Workers**: *Workers Scripts Edit* is
the legacy name of the *Editor* role at the Workers product scope. Creating a Worker that does not exist yet needs
*Admin* at the product scope, so the first deploy needs the account-wide permission. After that you can narrow the
token to *Editor* on just `<your-frontend-worker>` and `<your-api-worker>`, except for custom-domain changes, which
don't support per-Worker roles yet ([Workers roles and permissions](https://developers.cloudflare.com/workers/authorization/workers/)).
An account-wide token can edit **every** Worker on the account, so keep it to the account where you run Apotheke.

Optionally set a **client IP address filter** (your own address) and a **TTL** (an end date) on the token. Keep it in
the environment only, never in a file of the repository, a wrangler config, `.dev.vars` or shell history:

```sh
read -rs CLOUDFLARE_API_TOKEN && export CLOUDFLARE_API_TOKEN   # paste the token; nothing is echoed
export CLOUDFLARE_ACCOUNT_ID=<your-account-id>
```

Don't use `wrangler login` and a token at the same time; with `CLOUDFLARE_API_TOKEN` set, wrangler uses the token.

## Names: what is hard-coded today

The configs in the repository carry the project's own names, because the project's CI deploys them as they are. For
your account you change them **in your local checkout** (don't commit them back), until the parameterisation
follow-up lands:

| Where | Hard-coded today | Change to |
|---|---|---|
| `edge/app/wrangler.jsonc` | `"name": "getapotheke-app"` | `"name": "<your-frontend-worker>"` |
| `edge/app/wrangler.jsonc` | `API_BASE` (the project's API URL) | `https://api.example.com/api/v1` (once you run the API) |
| `edge/wrangler.jsonc` | `"name": "apotheke-api"` | `"name": "<your-api-worker>"` |
| `edge/wrangler.jsonc` | `ALLOWED_HOSTS` (the project's API host) | `api.example.com` |
| `edge/wrangler.jsonc` | `COOKIE_DOMAIN` (the project's domain) | `example.com`, or `""` (see [Settings](#settings)) |
| `edge/app/src/index.ts`, `frontend/public/_headers`, `frontend/scripts/check-dist.mjs` | the project's API host in the SPA's Content-Security-Policy (`img-src`, `connect-src`) | `https://api.example.com`, the same in all three (`check-dist.mjs` and `scripts/app-worker-check.mjs` expect them to match) |
| `frontend/index.html`, `frontend/public/robots.txt`, `frontend/public/sitemap.xml` | canonical, Open Graph and sitemap URLs of getapotheke.com | your front-end URL (they only matter for search engines and link previews) |
| `frontend/src/consent.js` | `CONSENT_PARENT`: the banner sets `Domain=` only for the project's domain | your registrable domain, if the front end and the API are on different hosts and should share the consent |
| `edge/src/index.ts` | `TZ: "Europe/Athens"` for the container | your time zone |

`wrangler deploy` also accepts `--name` (and `--var KEY:VALUE`), which change the name for one deploy without editing
the file; editing the local file is simpler to keep consistent for later deploys, secrets and rollbacks.

## Deploy the front end

This is the part that works today. With the token and account id in the environment, and `name` (and, if you run the
API, `API_BASE`) changed in `edge/app/wrangler.jsonc`:

```sh
(cd frontend && npm ci --ignore-scripts && npm run build)        # writes frontend/dist
cd edge/app
npx --no-install wrangler types --check --config wrangler.jsonc  # optional: the types still match the config
npx --no-install wrangler deploy --dry-run --config wrangler.jsonc --outdir "$(mktemp -d)"   # nothing is uploaded
npx --no-install wrangler deploy --config wrangler.jsonc
```

Then attach a domain ([Custom domains](#custom-domains)). `/config.json` answers `{"apiBase": …}` from `API_BASE`.

To try it locally first: `cd edge/app && npx --no-install wrangler dev --config wrangler.jsonc` (no account needed).

## The API (pending P4: D1 + R2)

> **Pending P4 (D1 + R2).** The API deploys, but it is stateless: no persistent disk, a new install on every cold
> start. Don't put real data in it. The steps below are for trying it out, and become the real install once the D1
> and R2 storage lands.

### Settings

The Worker passes these `vars` from `edge/wrangler.jsonc` to the container (`edge/src/index.ts`). They are not
secrets:

* **`ALLOWED_HOSTS`**: the API's host names (`api.example.com`). Any other `Host` gets 421, health checks included
  ([Host names](deployment-and-operations.md#host-names)).
* **`COOKIE_DOMAIN`**: your **registrable** domain (`example.com`), so the consent cookie also reaches your other
  hosts; `""` (empty) keeps it host-only. It applies only on that domain and hosts under it, and **only to the consent
  cookie**: never to the `__Host-apothekesid` session cookie, which may not carry a `Domain` attribute. Never use a
  public suffix (`co.uk`, `github.io`). See [Cookie consent](deployment-and-operations.md#cookie-consent).
* **`APOTHEKE_JOBS_WORKER: "0"`**, keep it: no long-lived `php spark jobs:work` in the container. The container
  runs only while requests keep it awake and starts with an empty `/data`, so a worker inside it would have no durable
  queue to work on. On Cloudflare, background jobs will run from a Cron Trigger or Queues, designed in P4 with the
  storage. The Docker images keep the worker on by default ([Background jobs](deployment-and-operations.md#background-jobs)).
* `SLEEP_AFTER_MS`: idle time before the container sleeps (`900000`, 15 minutes).
* **`CORS_ALLOWED_ORIGINS`** (pending): when the front end and the API are on different hosts, the API must list the
  front end's exact origin (`https://www.example.com`): exact origins, comma-separated, no `*`, `null` or paths
  ([JSON API: CORS](api.md#another-origin-cors), [Configuration](configuration.md)). **Today the Worker does not pass
  it to the container** (only the variables above, `APP_PASSWORD` and `APP_KEY` are passed), so setting it in `vars`
  has no effect yet. It needs the follow-up that forwards it (or a local change to `env` in `edge/src/index.ts`).

The container also has no outbound internet (`enableInternet: false`), so the AI autofill service can't be reached
from it.

### Secrets: APP_KEY and APP_PASSWORD

`edge/wrangler.jsonc` lists both under `secrets.required`, so a deploy fails until both exist on the Worker. Values
never go into `wrangler.jsonc`, `vars`, the repository, a chat or your shell history.

* **`APP_KEY`**: standard base64 of exactly 32 random bytes (44 characters; an optional `base64:` prefix is accepted).
  Anything else is refused as *APP_KEY is not a 32-byte key*. It encrypts stored secrets, so keep a copy somewhere
  safe ([Stored secrets: Key](configuration.md#key)). Generate it with `openssl rand -base64 32`, or
  `php bin/settings.php key generate` (prints a key, stores nothing).
* **`APP_PASSWORD`**: the password of the `admin` account, at least 8 characters, not only spaces.

Make both files private, then either pass them with the **first** deploy, or set them with `wrangler secret put`
on a Worker that already exists:

```sh
umask 077 && mkdir -p <private-dir>
openssl rand -base64 32 | tr -d '\n' > <private-dir>/app-key
# First deploy: a JSON secrets file (JSON, because the .env form expands $VAR inside values).
#   <private-dir>/api-secrets.json: {"APP_KEY": "<the key>", "APP_PASSWORD": "<your password>"}
cd edge && npx --no-install wrangler deploy --config wrangler.jsonc --secrets-file <private-dir>/api-secrets.json

# Later changes (run in edge/, with the name from your local config):
npx --no-install wrangler secret put APP_KEY --name <your-api-worker> < <private-dir>/app-key
npx --no-install wrangler secret put APP_PASSWORD --name <your-api-worker>   # typed at the prompt
npx --no-install wrangler secret list --name <your-api-worker>              # names only
```

`wrangler secret put` on a Worker that does not exist yet offers to create a draft Worker; answer no and use the
first-deploy form above. Delete the JSON file when you are done, or keep it only in `<private-dir>`.

### Deploy

With Docker running, the token and account id in the environment, and the names and settings changed locally:

```sh
cd edge
npx --no-install wrangler types --check --config wrangler.jsonc
npx --no-install wrangler deploy --dry-run --config wrangler.jsonc   # builds the container image, uploads nothing
npx --no-install wrangler deploy --config wrangler.jsonc             # add --secrets-file … the first time
npx --no-install wrangler containers list                            # the container application and its status
```

The first deploy takes a while (the image build and push), and the container can error for a few minutes while
Cloudflare provisions it. A request that cannot start the container gets a plain 503; the reason is only in the
Worker's logs (*Workers & Pages > <your-api-worker> > Logs*, or `wrangler tail`).

To try it locally: copy `edge/.dev.vars.example` to `edge/.dev.vars` (git-ignored), fill in generated values, and run
`npx --no-install wrangler dev` in `edge/` (needs Docker).

## Custom domains

A [Custom Domain](https://developers.cloudflare.com/workers/configuration/routing/custom-domains/) points a whole
host of your zone at a Worker; Cloudflare creates the DNS record and the certificate. Use one per Worker, for example
`www.example.com` for `<your-frontend-worker>` and `api.example.com` for `<your-api-worker>`. The host must not
already have a CNAME record.

* **Dashboard:** *Workers & Pages > (your Worker) > Settings > Domains & Routes > Add > Custom Domain*.
* **wrangler:** add `"routes": [{ "pattern": "www.example.com", "custom_domain": true }]` to your local config (or
  pass `--domain www.example.com` to `wrangler deploy`). This needs *Zone > Workers Routes > Edit* on the zone.

A **route** (`www.example.com/*`) works too, but needs a proxied DNS record for the host. Custom domains are simpler.

After you change hosts, update `ALLOWED_HOSTS`, `COOKIE_DOMAIN`, `API_BASE` and the SPA's CSP host to match
([Names](#names-what-is-hard-coded-today)).

## Upgrading and rolling back

1. Fetch the release and check it out: `git fetch --tags && git checkout vX.Y.Z`. Keep your local changes to the
   names and settings (for example as a small patch you re-apply: `git stash`, check out, `git stash pop`).
2. Read the **Upgrade note** of every release since yours in [CHANGELOG.md](../CHANGELOG.md) (and its GitHub Release).
3. Reinstall and rebuild: `(cd edge && npm ci --ignore-scripts)`, `(cd frontend && npm ci --ignore-scripts && npm run build)`.
4. Deploy the front end and, if you run it, the API, as above.

**Rolling back.** Each deploy is a new Worker version. List them with
`npx --no-install wrangler deployments list --name <your-frontend-worker>` and go back with
`npx --no-install wrangler rollback <version-id> --name <your-frontend-worker>` (or *Deployments > Rollback* in the
dashboard; [Rollbacks](https://developers.cloudflare.com/workers/configuration/versions-and-deployments/rollbacks/)).
The same works for `<your-api-worker>`, with two limits: a rollback is refused across a Durable Object migration
change, and the container image is rolled out separately from the Worker code
([Rollouts](https://developers.cloudflare.com/containers/configuration/rollouts/)). For the API the dependable way
back is to check out the previous release tag and deploy it again. Don't delete old images
(`wrangler containers images delete`) while you may still go back to a version that uses them.

## Backups

**Pending P4 (D1 + R2).** Once storage lands, a backup will be a D1 export (`wrangler d1 export`) plus the R2
objects (photos and files), and this section will give the exact steps.

What exists today: on Cloudflare there is nothing to back up, because the API keeps no data across cold starts. The
app's own backup (one zip file with the database and the photos; [Backup and restore](deployment-and-operations.md#backup-and-restore))
is for Docker and other installs with a disk. Do keep a copy of your `APP_KEY` and your local config changes.

## The project's CI is not for your account

The deploy workflows in `.github/workflows/` (`deploy-frontend.yml`, `deploy-edge.yml`), `scripts/cf-deploy.sh` and
the Worker guard `scripts/cf-worker-guard.mjs` are for the project's own account only. The guard refuses any Worker
name but the project's two (and `--name`, `--env`, `--route` or `--domain` arguments, and routes in the config), so
they fail on purpose for your names. Deploy by hand with `wrangler` as above, or write your own workflow in your
fork with your own token, account id and names. Don't change the guard to make the project's workflows deploy
elsewhere.
