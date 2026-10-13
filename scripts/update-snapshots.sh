#!/usr/bin/env bash
# Regenerate the Playwright visual baselines (tests/visual/__screenshots__) in the SAME environment as CI.
#
#   scripts/update-snapshots.sh                 run the official Playwright image locally with Docker
#   scripts/update-snapshots.sh --from-ci RUN   download the "snapshots" artifact of a CI run (gh CLI) instead
#   scripts/update-snapshots.sh [...] -- ARGS   extra Playwright args, e.g. -- --project=desktop-chromium
#
# Never commit screenshots rendered on a different OS/browser build: fonts and anti-aliasing differ.
set -euo pipefail
cd "$(dirname "$0")/.."
IMAGE="mcr.microsoft.com/playwright:v$(node -p "require('./package.json').devDependencies['@playwright/test']")-noble"
DIR=tests/visual/__screenshots__

if [[ "${1:-}" == "--from-ci" ]]; then
  run="${2:?usage: --from-ci RUN_ID (see: gh run list --workflow snapshots.yml)}"
  tmp="$(mktemp -d)"
  gh run download "$run" --name snapshots --dir "$tmp"
  rm -rf "$DIR" && mkdir -p "$DIR" && cp -R "$tmp"/. "$DIR"/
  rm -rf "$tmp"
  echo "Baselines from run $run copied to $DIR – review with git status / git diff --stat, then commit."
  exit 0
fi
[[ "${1:-}" == "--" ]] && shift
command -v docker >/dev/null || { echo "Docker is required (or use --from-ci RUN_ID)." >&2; exit 1; }
docker network create apotheke-e2e >/dev/null 2>&1 || true
docker rm -f apotheke-e2e-pg >/dev/null 2>&1 || true
docker run -d --rm --name apotheke-e2e-pg --network apotheke-e2e -e POSTGRES_USER=apotheke -e POSTGRES_PASSWORD=apotheke -e POSTGRES_DB=apotheke postgres:17 >/dev/null
trap 'docker rm -f apotheke-e2e-pg >/dev/null 2>&1 || true' EXIT
docker run --rm --ipc=host --network apotheke-e2e -v "$PWD":/work -w /work -e CI=1 -e TEST_PG_HOST=apotheke-e2e-pg -e TEST_PG_PASSWORD=apotheke \
  "$IMAGE" bash -c 'scripts/e2e-container-setup.sh && for i in $(seq 30); do php -r "exit(@fsockopen(\"apotheke-e2e-pg\",5432)?0:1);" && break; sleep 1; done; npm ci --no-audit --no-fund && npx playwright test --update-snapshots=all --retries=0 "$@"' _ "$@"
echo "Updated $DIR – review the changes, then commit them."
