#!/usr/bin/env bash
# Decide whether a CI matrix cell runs PHPUnit.
# stdout is a single run=true or run=false line for GITHUB_OUTPUT.
# The explanation goes to stderr so the Actions log shows why a leg skipped.
set -euo pipefail

app=${1:?usage: ci4-leg.sh <legacy|ci4> [root]}
root=${2:-.}

say() { echo "$*" >&2; }

case "$app" in
  legacy)
    echo "run=true"
    exit 0
    ;;
  ci4)
    ;;
  *)
    say "APOTHEKE_APP must be legacy or ci4 (got ${app})."
    exit 2
    ;;
esac

if [ ! -f "$root/public/index.php" ]; then
  say "public/index.php is missing."
  say "The CI4 leg cannot pass without the front controller."
  exit 1
fi

# shellcheck disable=SC2016
php -r '
require $argv[1];
if (!function_exists("apotheke_app") || !function_exists("apotheke_front_controller")) {
    fwrite(STDERR, "tests/lib.php does not switch servers on APOTHEKE_APP.\n");
    exit(1);
}
putenv("APOTHEKE_APP=ci4");
if (apotheke_app() !== "ci4") {
    fwrite(STDERR, "apotheke_app() did not select ci4.\n");
    exit(1);
}
$front = str_replace("\\\\", "/", apotheke_front_controller());
if (!str_ends_with($front, "/public/router.php")) {
    fwrite(STDERR, "front controller does not point at public/router.php.\n");
    exit(1);
}
putenv("APOTHEKE_APP=legacy");
if (apotheke_app() !== "legacy") {
    fwrite(STDERR, "apotheke_app() did not select legacy.\n");
    exit(1);
}
$legacy = str_replace("\\\\", "/", apotheke_front_controller());
if (str_ends_with($legacy, "/public/router.php")) {
    fwrite(STDERR, "legacy front controller still points at public/router.php.\n");
    exit(1);
}
' "$root/tests/lib.php"

echo "run=true"
