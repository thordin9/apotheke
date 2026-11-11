# Storage-mode preview persistence check

A DevOps checklist for a [Workers Preview](https://developers.cloudflare.com/workers/previews/) that already has `STORAGE` (R2), `DB` (D1) and `INSTALLATION` bound: prove that an item and its photo survive a container sleep and a cold start. The check is the script [`scripts/storage-preview-check.sh`](../scripts/storage-preview-check.sh). It talks only to the Preview's HTTP API. It does not deploy, and it does not call the Cloudflare API.

How to stand up that Preview (throwaway EU D1 and R2, `wrangler preview`, the D1 `changes()` probe) is [Preview verification](deployment-and-operations.md#3-preview-verification-before-the-flip-nothing-is-promoted) in the flip checklist. Run this check after that probe, on the same Preview, before clean-up.

## Needs

- the Preview URL and a throwaway admin password for it (never production secrets)
- `curl`, `jq`, and `sha256sum` (or `shasum -a 256`)
- a checkout of this repository, so the script can read `SLEEP_AFTER_MS` from `edge/wrangler.jsonc` (15 minutes on current `main`)

There is no supported in-repo or Wrangler switch that forces the container to sleep without calling the Cloudflare API, so the script waits past the configured inactivity timeout instead.

## Run

Inputs come only from the environment or flags. Use placeholders here; put the real URL and password only in your shell, never in the repository.

```sh
cd <checkout>
BASE_URL=https://<preview-host> \
ADMIN_USER=admin \
ADMIN_PASSWORD=<preview-admin-password> \
scripts/storage-preview-check.sh
```

`ADMIN_USER` defaults to `admin`. `SLEEP_WAIT_SECONDS` (or `--sleep-seconds`) overrides the wait: the default is `SLEEP_AFTER_MS` from `edge/wrangler.jsonc`, in seconds, plus 30. `0` skips the wait, which is only useful as a local dry run (it still creates the item, downloads the photo, and cleans up, but it does not prove a cold start).

Equivalent flags: `--base-url`, `--user`, `--sleep-seconds`. The password is read from `ADMIN_PASSWORD` only, so it stays out of the process list and shell history. `--help` prints the same summary and exits 0.

The first `/healthz` after a sleep may cold-start the container; the script retries that for about two minutes, as the preview plan does.

## What it checks

Cookie consent (`apotheke_consent=v1`) and sign-in follow the JSON API: `GET` then `POST /api/v1/session` with `Origin` and `X-CSRF-Token` ([JSON API](api.md), [OpenAPI](api/openapi.yaml)). There is no item or photo route in `/api/v1` yet, so the script then uses the app's HTTP form routes, the same ones the pages use:

1. `POST /save` creates an item named `storage-preview-check <UTC timestamp>` and attaches a tiny PNG generated at runtime (the app stores a served JPEG).
2. `GET /item/{id}` must show that name. The script records the sha256 of `GET /media/full/<32hex>.jpg`.
3. It waits `SLEEP_WAIT_SECONDS` without sending further requests, then `GET /healthz` to cold-start the container.
4. It signs in again (sessions still live on the container disk, so they may be gone after a sleep) and repeats the item and photo reads. The photo sha256 must be unchanged.
5. On the way out it deletes the test item (`POST /item/{id}/delete`), best effort. A failure to delete is a warning; it does not hide a check failure. If the warning appears, delete that item on the Preview by hand.

The script exits non-zero and prints `error:` on the first failed step (HTTP status, missing item or photo, or a sha256 mismatch). It never prints the password or the CSRF token.

## Pass and fail

A pass ends with `ok storage-mode preview persistence check` and exit 0. Any other exit means the flip waits: the item or the photo did not come back after the cold start, or the Preview was not reachable. Fix the Preview's storage bindings or the app, then run the check again.

## Local dry run

Against a local app (sign-in on, an admin password set), the same command works with `BASE_URL=http://127.0.0.1:<port>` and `SLEEP_WAIT_SECONDS=0`. That does not prove container sleep.
