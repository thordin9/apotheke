# QA: apotheke #272, storage-mode preview persistence check

- PR: https://github.com/thordin9/apotheke/pull/272 (Coder)
- Reviewed head: `3190c1e7251f02795ece4bd699378a1921ce9e1a` (fix delta on top of `8b78e14f`, first read at that head)
- Gate: light (diff read, CI, shellcheck and input checks on the script; no suites, no live run)
- Reviewer: Apotheke QA 2

## Checklist

- [x] Fix delta `3190c1e7` (parent `8b78e14f`): `scripts/storage-preview-check.sh` +8/−12 and `docs/storage-preview-check.md` +1/−1. It closes all three findings below. `--password x` now exits 2 (unknown option), the health check is bounded at about 140 s (a 120 s deadline, plus up to 15 s per try and 5 s sleeps), and `base64 --decode` still decodes the PNG. shellcheck is clean.
- [x] PR: two commits, 4 files: `scripts/storage-preview-check.sh` (+297), `docs/storage-preview-check.md` (+51), `docs/index.md` (+1), `docs/deployment-and-operations.md` (+5). 0 behind main, `mergeable_state` clean.
- [x] CI at `3190c1e7`: 21/21 green, including Secret scan, Lint, Conventional Commits and D1 (local workerd).
- [x] `shellcheck -x` and `bash -n` are clean. `--help` exits 0. A missing `BASE_URL`, a non-http(s) `BASE_URL` and a negative `SLEEP_WAIT_SECONDS` each exit 1 with a clear `error:` line.
- [x] The routes the script uses exist at this head: `GET /healthz`, `GET`/`POST /api/v1/session`, `POST /save` (the JSON redirect or 303 to `/?saved=<id>`), `GET /item/{id}`, `GET /media/full/<file>` and `POST /item/{id}/delete`. `SLEEP_AFTER_MS` is the string `"900000"` in `edge/wrangler.jsonc`, which matches the script's parser (15 min + 30 s default).
- [x] Hygiene: no real hostnames, keys or passwords, and the docs use only `<preview-host>` and `<preview-admin-password>` placeholders. The password goes to curl through a 0700 `mktemp -d` file that is removed right after use. The cookie jar and work dir are removed on exit, and the password and CSRF token are never echoed. The script does not deploy or call the Cloudflare API, as stated. Test-item cleanup is best effort and doesn't mask the exit status.
- [x] Docs: the checklist is linked from `docs/index.md` and from the Preview verification step in `docs/deployment-and-operations.md`, and its known limits are stated (a wait can't prove a sleep, `0` is a dry run only). The commit subject is a valid Conventional Commit. No CHANGELOG line (docs and ops tooling only; expected).

## Findings

| ID | Grade | Finding |
|---|---|---|
| L-1895 | Low (closed in `3190c1e7`) | `--password` puts the admin password in the process arguments (visible to `ps` and kept in shell history), and the doc lists it as equivalent to `ADMIN_PASSWORD`. Hardening: drop the flag, or have the doc say to use the env var only. |
| N-1896 | Nit (closed in `3190c1e7`) | `wait_healthz` says "waited ~2 min", but 24 tries with a 60 s curl timeout plus a 5 s sleep can take about 26 min against a hanging host. Lower `--max-time` for `/healthz` or reword. |
| N-1897 | Nit (closed in `3190c1e7`) | `base64 -d` assumes GNU coreutils (older macOS needs `-D`), while the `shasum` fallback suggests macOS is supported. Name GNU `base64` under Needs, or use `base64 --decode`. |

0 High, 0 Medium, 1 Low, 2 Nits, all closed. None open.

## Verdict

**PASS** at `3190c1e7`. Not run against a live Preview; the first real run on the flip Preview is the functional proof. Squash-merge with the PR title as the subject.
