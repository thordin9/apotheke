#!/usr/bin/env bash
# Validate that commit subjects follow Conventional Commits.
# Usage: scripts/check-commits.sh <base-ref> [<head-ref>]   (checks base..head)
#        scripts/check-commits.sh --message "feat: subject"  (checks one subject)
set -euo pipefail
re='^(feat|fix|docs|style|refactor|perf|test|build|ci|chore)(\([a-z0-9._/-]+\))?!?: [^ ].*$'
check() {
  local subject=$1 label=$2
  if [[ $subject =~ ^(Merge|Revert|fixup!|squash!)\  ]]; then echo "skip  $label: $subject"; return 0; fi
  # Release commits are written by .github/workflows/release.yml (see docs/releasing.md).
  if [[ $subject =~ ^chore\(release\):\ v[0-9]+\.[0-9]+\.[0-9]+ ]]; then echo "skip  $label: $subject (release)"; return 0; fi
  if [[ ! $subject =~ $re ]]; then
    echo "FAIL  $label: \"$subject\" is not <type>[(scope)][!]: <subject>"; return 1
  fi
  if (( ${#subject} > 72 )); then
    echo "FAIL  $label: subject is ${#subject} chars (max 72)"; return 1
  fi
  echo "ok    $label: $subject"
}
if [[ ${1:-} == --message ]]; then check "${2:-}" message; exit; fi
base=${1:?usage: check-commits.sh <base> [<head>]}
head=${2:-HEAD}
fail=0; n=0
while IFS= read -r line; do
  sha=${line%% *}; subject=${line#* }
  n=$((n + 1))
  check "$subject" "${sha:0:7}" || fail=1
done < <(git log --no-merges --format='%H %s' "$base..$head")
echo "$n commit(s) checked"
if (( fail )); then
  echo "Allowed types: feat fix docs style refactor perf test build ci chore. See CONTRIBUTING.md." >&2
  exit 1
fi
