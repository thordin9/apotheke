# QA: apotheke #304, per-installation APP_KEY and allowed hosts

- PR: https://github.com/thordin9/apotheke/pull/304 (Coder), base `main`. Operator console E3b, stacked on #299, #302 and #303 (all merged).
- Approved head: `2915018cbc05c3a7b58baaf75b6603c943cebfaf`, parents `14b37ec6` and main `b58cc830` (plain merge, v3.42.5). 0 behind main `b58cc830`, 34 ahead, mergeable (clean).
- History: 34 commits, 15 non-merge (all Conventional Commits, 46 to 70 chars, `check-commits.sh` 15/15 ok), 19 git-generated merges (exempt). The old stacked commits are already on main by content: the tree diff `main...2915018c` is exactly 7 files, +176/-5. PR title: "feat(edge): per-installation APP_KEY and allowed hosts" (54).
- Gate: full (security-relevant code). Code review, an independent HKDF check, the CI edge steps run locally, 20 mutants, docs, CHANGELOG and a secret scan.
- CI on `2915018c`: CI 43433960955, Deploy edge 43433960996 (dry run) and PR #304 dynamic (CodeQL) 43433949256 all succeeded. 23 check runs: 22 success incl. Tests (PHP 8.4, sqlite) and (PHP 8.4, pgsql), 1 skipped (Deploy apotheke-api, PR event).
- Reviewer: Apotheke QA 2

## Checklist

- [x] Files: CHANGELOG +4, `docs/operator-console.md` +14, `edge/src/index.ts` +21/-4, `edge/src/installation.ts` +31, `installation-isolation.test.mjs` +2/-1, `installation-secrets.test.mjs` +103 (new), `r2.test.mjs` +1. `route.test.mjs` and `edge/migrations/` are identical to main (L-2142's 5_000 test kept). No new migration, and the sha256 pin test for 0001/0002 passes.
- [x] HKDF: WebCrypto `importKey("raw", ikm, "HKDF")` and `deriveBits` SHA-256, 256 bits, UTF-8 salt `apotheke-installation-app-key-v1`, info = `do_name`, output `base64:` + 44 chars. The master is decoded as PHP's `SecretKey::bytes` does (trim, optional `base64:`, strict base64, padding optional), and anything not 32 bytes throws. An independent Python HMAC-SHA256 HKDF (RFC 5869) matches for 3 names with the dummy key bytes `00..1f` (e.g. `tenant-b` gives `base64:67WhNK5v…`).
- [x] Separation: `do_name` is UNIQUE and fixed by trigger, and `primary` is seeded, so no other installation can be named `primary` or share an info string. `primary` (and an unnamed object, as before) gets `APP_KEY` and `ALLOWED_HOSTS` unchanged and never touches `DB` (tested with a throwing proxy).
- [x] Hosts: one parametrized query (`WHERE i.do_name = ?`), reading only `installation_routes.host`/`installation_id` and `installations.id`/`do_name`, `ORDER BY r.host`. Hosts are lower case with no comma by the schema CHECK. The probe uses the installation's first host.
- [x] Fails closed: a bad master key, no `DB`, a D1 error or no route gives 503 before `c.start`. Errors carry fixed text only, with no key material. No module-level state: the key and hosts are computed per start inside the object (Cloudflare phase: values come from bindings and D1 only).
- [x] Run as CI does (Node 24, `npm ci` in `edge/`): deploy-scripts + edge tests 146/146 (5 E3b), `wrangler types --check`, `tsc --noEmit`, `check-links`, `check-commits` all pass.
- [x] Mutants, 20 (each restored): killed 17, including no primary passthrough, salt changed, empty info, salt/info swapped, master returned for another installation, 128-bit output, no `base64:` strip, no trim, no sort, no `do_name` filter, empty hosts allowed, Worker hosts or key for another installation, primary reading D1, unnamed not primary, probe on Worker hosts, space-joined hosts. 3 survive as equivalent: the `=== 32` check and the 43-char regex each guard length alone (both loosened together: killed), and dropping the `!this.env.DB` check still fails closed on a TypeError.
- [x] CHANGELOG: `[Unreleased]` has only `### Added` with the #304 bullet and its full URL. The header and everything from `## [3.42.5]` down are byte-identical to main.
- [x] Secret scan of the 183 added lines: 0 hits in 8 patterns (cloud keys, tokens, PEM, hex ids, UUIDs, `base64:` keys, assignments, long base64). Test keys are fakes built at run time. No conflict markers.

## Findings

- **L-2153 (docs, hardening):** the new operator-console section documents the startup requirements but not the operator caveats the PR itself names. Rotating the Worker `APP_KEY` changes every derived key and nothing re-encrypts the other installations' stored secrets (`key rotate` needs `APP_KEY_NEW`, which the edge does not pass), and every installation shares the Worker's `APP_PASSWORD` until C1. Fix: add two sentences to `## Per-installation APP_KEY and ALLOWED_HOSTS` (keep the master as is until a re-encrypt step exists; shared `APP_PASSWORD` until C1).
- **N-2154:** in `index.ts`, the new `installationEnv` docstring is inserted under `startAndWait`'s, so two `/** */` blocks stack above `installationEnv` and `startAndWait` has none. Fix: move "Starts the container unless it is running…" back above `startAndWait`.
- **N-2155:** the key and hosts are read only at container start. A route added or removed while the container runs takes effect at the next start, and a running-but-not-ready container is probed on the new first host. It self-heals (two timed-out waits destroy it). Fix: one line in the doc.

0 High, 0 Medium, 1 Low, 2 Nit open.

## Verdict

**PASS** at `2915018c`, 0 behind main `b58cc830`. Highs and Mediums block; none are open. L-2153, N-2154 and N-2155 can be fixed here (a delta read and a reissued doc) or as a follow-up. Squash-merge with the PR title as the subject once CI on the doc commit is green. If main moves before merge, the PR needs a plain catch-up (conflicts reported as conflicts), a merge-only read and a reissued doc.
