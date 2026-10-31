# Page-view analytics (Counterscale)

getapotheke.com counts page views with [Counterscale](https://github.com/benvinegar/counterscale), a small
open-source analytics service on Cloudflare Workers and Workers Analytics Engine. It runs as its own Worker on the
project's Cloudflare account at `https://analytics.services.webapp.gr`. This page covers the site side, deploying
and upgrading the Worker, and who can read the numbers. The visitor-facing description is the
[privacy page](../frontend/privacy/index.html).

Pinned version: **Counterscale v3.4.1** (the server tag and `@counterscale/tracker` 3.4.1 in
`frontend/package.json`).

## On the site

* `frontend/src/analytics.js` loads the tracker (`@counterscale/tracker`, bundled by Vite into its own chunk under
  `/assets/`) with a dynamic `import()`. There is no third-party script, so `script-src 'self'` is unchanged.
* It runs only with consent: after **Accept**, or on a later visit that already has the `apotheke_consent=v1`
  cookie. Before Accept and after **Decline** the chunk is not downloaded and nothing is sent. Every page view
  checks the cookie again, so a deleted or changed cookie stops counting from the next view.
* Withdrawing is as easy as accepting (GDPR Art. 7(3)): **Cookie settings** in the footer
  (`frontend/src/partials/footer.html`, shown once JavaScript runs) reopens the notice with the current choice.
  **Decline** there expires the cookie with the Domain and Path it was set with (`Max-Age=0`) and stops the tracker
  without a reload.
* Settings: `siteId: 'getapotheke.com'`, `reporterUrl: 'https://analytics.services.webapp.gr/collect'`,
  `autoTrackPageviews: false`. `consent.js` counts `/`, `/privacy/` and not-found pages (a 404 is counted under
  the path that was requested, not `/404.html`, because the page is served at that URL); on `/app/` the router counts
  one view per route render (first load, in-app links through `history.pushState`, back and forward). The URL is
  passed explicitly, because the tracker otherwise prefers `<link rel="canonical">`, which is `/app/` for every
  app route.
* Each view is two `GET` requests: `/cache?sid=…` and `/collect?sid=…&h=…&p=…&r=…`. Only the path is sent, not the
  query string (UTM tags are sent as their own parameters). The referrer is `document.referrer` from another site,
  without its query string; when there is none, the tracker takes `ref`, `referer`, `referrer`, `source` or
  `utm_source` from the page's own query string. Views inside the app repeat the referrer of the page the visitor
  landed on, because `document.referrer` does not change on `pushState`. The tracker stores nothing on the device: repeat
  visits on the same day are recognised through the browser's HTTP cache (`If-Modified-Since`/`Last-Modified`).
* `connect-src https://analytics.services.webapp.gr` is in both Content-Security-Policies, identical in
  `frontend/public/_headers`, `frontend/scripts/check-dist.mjs` and `edge/app/src/index.ts`.
* Tests: `tests/e2e/consent.spec.ts` (*page-view counting*). The tracker skips `localhost` and `127.x`, so those
  tests open the site as `http://apotheke.test:4175`, which `tests/e2e/playwright.config.ts` maps to 127.0.0.1
  with Chromium's `--host-resolver-rules`. `page.route` answers the analytics requests; nothing leaves the runner.

## What the Worker stores

Per view, in the Analytics Engine dataset: site id, host, path, referrer, UTM parameters, the user-agent string and
what is parsed from it (browser, major version, device type and model), the country from Cloudflare's request
properties, and visit and bounce flags. No IP address and no visitor id. Analytics Engine keeps data for
**90 days**. With storage on, a daily cron (`0 2 * * *`) writes the previous day as an Apache Arrow file to an R2
bucket: counts per day for each combination of those fields, kept until deleted.

## Deploying

Everything here is a change in the Cloudflare account and needs the account owner's approval first. Use
placeholders in anything committed; keep real values on the machine you deploy from.

Deploy from the pinned tag with a local config rather than `npx @counterscale/cli install`. The 3.4.1 CLI is
interactive, runs an unpinned `npx wrangler`, expects `wrangler login`, and deploys with the `workers.dev` URL on,
which would be a second public hostname outside Access.

```sh
git clone --depth 1 --branch v3.4.1 https://github.com/benvinegar/counterscale.git
cd counterscale
corepack enable && pnpm install --frozen-lockfile   # pnpm 9.15.0, Node 20 or later
pnpm --filter @counterscale/server build
cd packages/server
```

Next to `packages/server/wrangler.json`, write `wrangler.local.json` (not committed anywhere). It is the upstream
file plus the custom domain, with the `workers.dev` and preview URLs off:

```json
{
  "name": "counterscale",
  "main": "./workers/app.ts",
  "compatibility_date": "2024-12-13",
  "compatibility_flags": ["nodejs_compat_v2"],
  "workers_dev": false,
  "preview_urls": false,
  "routes": [{ "pattern": "analytics.services.webapp.gr", "custom_domain": true }],
  "assets": { "directory": "./build/client", "binding": "ASSETS" },
  "analytics_engine_datasets": [{ "binding": "WEB_COUNTER_AE", "dataset": "metricsDataset" }],
  "r2_buckets": [{ "binding": "DAILY_ROLLUPS", "bucket_name": "counterscale-daily-rollups" }],
  "triggers": { "crons": ["0 2 * * *"] }
}
```

Compare it with the tag's `wrangler.json` before each deploy, and carry over anything new upstream added.
Without R2, drop `r2_buckets` and the cron, and set `CF_STORAGE_ENABLED` to `false`. The custom domain creates the
DNS record and its own certificate; a two-level name such as `analytics.services` is covered without Advanced
Certificate Manager. If the zone does not redirect HTTP to HTTPS everywhere, add a redirect rule for this host,
so the sign-in form is never sent over plain HTTP.

Then, with an API token in the environment of that one command (never on the command line or in a file in a
repository):

```sh
pnpm exec wrangler deploy --config wrangler.local.json --var VERSION:v3.4.1
```

### Secrets

Set with `pnpm exec wrangler secret put <NAME> --config wrangler.local.json`, values typed at the prompt:

| Name | Value |
|---|---|
| `CF_ACCOUNT_ID` | the account id |
| `CF_BEARER_TOKEN` | a separate API token with only *Account Analytics: Read* on this account (the dashboard queries Analytics Engine with it) |
| `CF_AUTH_ENABLED` | `true` |
| `CF_PASSWORD_HASH` | a bcrypt hash (cost 12) of the dashboard password; the password itself is not stored |
| `CF_JWT_SECRET` | 64 random bytes, hex |
| `CF_STORAGE_ENABLED` | optional; `false` when there is no R2 bucket |

### Token permissions

* Deploying: *Workers Scripts: Edit* (creating a new Worker needs the Workers admin scope), *Workers Routes: Edit*
  on the zone (for the custom domain), and *Workers R2 Storage: Edit* only to create the bucket. The Analytics
  Engine dataset is created on its first write.
* The Worker's own `CF_BEARER_TOKEN`: *Account Analytics: Read*, nothing else.
* Access (below): *Access: Apps and Policies: Edit*.

## Access

Counterscale's own password is not enough on 3.4.1: upstream issue
[#272](https://github.com/benvinegar/counterscale/issues/272) reports that most of the dashboard's data routes
(`/resources.country`, `/resources.browser`, `/resources.browserversion`, `/resources.device`,
`/resources.referrer` and the `/resources.utm-*` routes) answer without a session, for any site id. So the whole
host sits behind Cloudflare Access, and the password stays on as a second layer:

| Access application | Policy |
|---|---|
| `analytics.services.webapp.gr` (the whole host) | **Allow**: the maintainer's identity only (`<maintainer-email>`) |
| `analytics.services.webapp.gr/collect` and `analytics.services.webapp.gr/cache` | **Bypass**: Everyone |

Access applies the most specific application, so the two tracker endpoints stay public and everything else needs
the maintainer's sign-in. `/tracker.js` stays behind Access: the site bundles the tracker instead. `/collect`
accepts any site id from any origin; Counterscale has no allowlist, so stray data is possible and the numbers
should be read with that in mind. Keep `workers_dev` and `preview_urls` off, or those hostnames bypass Access.

## Upgrading

1. Read the release notes between the pinned version and the new one, and whether #272 is fixed.
2. Bump **both** together in one pull request: the tag you deploy from, and `@counterscale/tracker` in
   `frontend/package.json` (exact version, `npm install --save-exact`), plus the version on this page. The tracker
   and the `/collect` parameters change together upstream.
3. Re-check the local config against the new `wrangler.json`, build, deploy, and open the dashboard.
4. Rolling back: deploy the previous tag the same way (`wrangler rollback` also works for the Worker itself).
   The data in Analytics Engine and R2 is not touched by a deploy.
