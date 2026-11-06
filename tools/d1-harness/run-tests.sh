#!/usr/bin/env bash
# Local-only D1: pinned wrangler/workerd, no credentials or remote endpoints.
if (( $# == 0 )); then
  set -- tests/Modules/Core/SqlExecutorTest.php tests/Modules/Core/D1HttpExecutorTest.php \
    tests/Modules/Migrations/D1MigrationRunnerTest.php tests/Modules/Core/D1IntegrationTest.php \
    tests/Modules/Core/UnitOfWorkTest.php
fi
set -euo pipefail
exec env -u CLOUDFLARE_API_TOKEN -u CF_API_TOKEN -u CF_WORKERS_DEPLOY_TOKEN -u CF_TOKEN_EDGE_DEPLOY -u CF_TOKEN_STATIC_DEPLOY \
  "$(dirname "$0")/run.sh" "$(cd "$(dirname "$0")/../.." && pwd)" "$(cd "$(dirname "$0")" && pwd)/test-driver.php" "$@"
