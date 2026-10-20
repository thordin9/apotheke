#!/usr/bin/env bash
# Install pinned actionlint into the directory given as $1.
set -euo pipefail

version=1.7.7
# sha256 of actionlint_1.7.7_linux_amd64.tar.gz from the GitHub release.
expected="023070a287cd8cccd71515fedc843f1985bf96c436b7effaecce67290e7e0757"
dest=${1:?usage: install-actionlint.sh <dest-dir>}

if [ "$(uname -s)" != "Linux" ] || [ "$(uname -m)" != "x86_64" ]; then
  echo "pinned actionlint build is linux x64; this host is $(uname -s) $(uname -m)" >&2
  exit 1
fi

file="actionlint_${version}_linux_amd64.tar.gz"
url="https://github.com/rhysd/actionlint/releases/download/v${version}/${file}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$dest"
curl -fsSL --retry 3 --retry-delay 2 -o "$tmp/$file" "$url"
echo "${expected}  $tmp/$file" | sha256sum -c -
tar -xzf "$tmp/$file" -C "$tmp" actionlint
install -m 0755 "$tmp/actionlint" "$dest/actionlint"
