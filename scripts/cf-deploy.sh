#!/usr/bin/env bash
# cf-deploy.sh WORKER DIR [wrangler deploy args...]: the deploy step of deploy-frontend.yml and deploy-edge.yml.
# Runs the Worker guard, then `wrangler deploy` in DIR (its wrangler.jsonc), and on failure names the likely cause
# (a missing token permission, a missing Worker secret) without printing the token or the account id.
# Needs CLOUDFLARE_API_TOKEN and CLOUDFLARE_ACCOUNT_ID in the environment (the job masks the account id).
set -euo pipefail
worker=${1:?usage: cf-deploy.sh WORKER DIR [args]}; dir=${2:?usage: cf-deploy.sh WORKER DIR [args]}; shift 2
root=$(cd "$(dirname "$0")/.." && pwd)
case "$worker" in
  getapotheke-app) perms='Account > Workers Scripts > Edit (environment deploy-site)' ;;
  apotheke-api) perms='Account > Workers Scripts > Edit and Account > Containers > Edit (environment deploy-edge)' ;;
  *) echo "::error title=Worker guard::$worker is not a Worker this repository deploys"; exit 1 ;;
esac
: "${CLOUDFLARE_API_TOKEN:?CLOUDFLARE_API_TOKEN is not set}"
: "${CLOUDFLARE_ACCOUNT_ID:?CLOUDFLARE_ACCOUNT_ID is not set (variable of the GitHub environment)}"
node "$root/scripts/cf-worker-guard.mjs" "$worker" "$dir/wrangler.jsonc" -- "$@"
log=$(mktemp); trap 'rm -f "$log"' EXIT
cd "$dir"
code=0
WRANGLER_SEND_METRICS=false npx --no-install wrangler deploy "$@" > "$log" 2>&1 || code=$?
cat "$log"
if [ "$code" = 0 ]; then exit 0; fi
if grep -qiE 'Authentication error|code: 10000|code: 10001|Unauthorized|\b403\b|not authorized|permission' "$log"; then
  echo "::error title=Cloudflare refused the token::Deploying $worker was refused (authentication or 403). The token of this environment likely lacks: $perms. Check the CLOUDFLARE_API_TOKEN secret and the CLOUDFLARE_ACCOUNT_ID variable."
elif grep -qF 'required secrets have not been set' "$log"; then
  echo "::error title=Worker secrets missing::$worker needs its secrets set once outside CI (wrangler secret put APP_PASSWORD and APP_KEY, see docs/deployment-and-operations.md)."
elif grep -qiE 'registry|containers?/|image push' "$log"; then
  echo "::error title=Container push failed::Pushing the container image for $worker failed. If it is a permission error, the token needs Account > Containers > Edit."
else
  echo "::error title=Deploy failed::wrangler deploy for $worker failed (exit $code); see the log above."
fi
exit "$code"
