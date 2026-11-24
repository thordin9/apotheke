#!/bin/sh
# rqlite container entrypoint for the self-host stack (plan §11 rule 4, S0.9).
#
# Why a wrapper: rqlite's `-auth` is a *file* of users, while the app's contract is one variable,
# `RQLITE_AUTH=username:password` — the same value the app sends as a Basic credential. Writing the
# file from that variable keeps one secret in one place (`.env`) and out of the compose file.
#
# With `RQLITE_AUTH` empty the node starts without authentication, which is the documented default
# for a single-host install: port 4001 is not published (§11 rule 1), so only the compose network can
# reach it.
#
# Everything after this exec is the image's own `/bin/docker-entrypoint.sh`, which fills in
# `-http-addr`/`-raft-addr`/node id from the environment and appends the data directory.
set -eu

auth=""
if [ -n "${RQLITE_AUTH:-}" ]; then
	user="${RQLITE_AUTH%%:*}"
	pass="${RQLITE_AUTH#*:}"
	mkdir -p /rqlite/file
	# 0600: the file lives on the data volume next to the database, not in the image.
	printf '[{"username":"%s","password":"%s","perms":["all"]}]\n' "$user" "$pass" \
		> /rqlite/file/auth.json
	chmod 600 /rqlite/file/auth.json
	auth="-auth /rqlite/file/auth.json"
fi

# `$auth` is intentionally unquoted: empty must expand to no argument at all.
# shellcheck disable=SC2086
exec /bin/docker-entrypoint.sh $auth -http-addr 0.0.0.0:4001 -raft-addr 0.0.0.0:4002
