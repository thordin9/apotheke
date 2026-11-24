#!/bin/sh
# Apotheke v4 skeleton image entrypoint (plan §5 S0.9) — runs the real Worker under `wrangler dev`.
#
# Compose hands the app its configuration as container environment variables, and `wrangler dev`
# reads vars from `wrangler.jsonc` plus `--var`. This turns the first into the second, so `.env` stays
# the single source of configuration and no secret is ever written into a file or a layer.
#
# Empty values are skipped on purpose: `wrangler.jsonc` already carries the defaults, and an empty
# `--var` would override them with "" — which is exactly what the config loader reads as "unset", so
# both roads lead to the default. Skipping keeps the command line readable.
set -eu

# 9091 is the published port (§11). State — including the database under DB_DRIVER=sqlite — lives in
# the mounted volume, so `docker compose restart` and image upgrades keep it.
set -- node_modules/.bin/wrangler dev --ip 0.0.0.0 --port 9091 --persist-to /data/state

for name in \
	DB_DRIVER RQLITE_URL RQLITE_AUTH RQLITE_READ_CONSISTENCY \
	APP_PASSWORD APP_KEY APOTHEKE_CLOCK APOTHEKE_HOSTED PUBLIC_URL \
	CURRENCY TZ PAGE_SIZE MAX_UPLOAD_MB IMAGE_FULL_MAX IMAGE_THUMB_MAX EXPIRY_SOON_DAYS
do
	value=$(printenv "$name" || true)
	if [ -n "$value" ]; then
		set -- "$@" --var "$name:$value"
	fi
done

exec "$@"
