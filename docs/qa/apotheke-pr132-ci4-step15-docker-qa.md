# Apotheke PR #132: CI4 step 15 Docker image QA

| | |
|---|---|
| PR | https://github.com/thordin9/apotheke/pull/132 |
| Head | `cd15df108cb61643249acbdb05319b8579dc1161` (CI 40442561790 21/21); code verified at `7942fc7`, `cd15df1` is docs-only |
| Base | `feature/ci4` `ad343bf8d77199934650584ac4e1e790c493919c` |
| Scope | Delta gate `996a05d..7942fc7`: L-430, N-431, N-432, PHP 8.4.27 default for both apps; diff read `7942fc7..cd15df1` (L-435) |
| Images | podman, own storage: head 8.4 146 MB (default), head 8.3 build-arg 142 MB, base 8.3 127 MB |

## Checklist

| Check | Result |
|---|---|
| Both apps on 8.4.27 | Pass: `PHP_VERSION` 8.4.27 in legacy and ci4 containers |
| Legacy on 8.4 (default) | Pass: smoke, docker-checks, auth-checks (restore 204/401/419/421 unchanged), signin-checks incl. full exit-78 matrix, docker-heic |
| CI4 on 8.4 | Pass: smoke, docker-checks, signin-checks, auth-checks (`FAIL_MS=1500`) |
| 8.3 build-arg, `APOTHEKE_APP=ci4` | Pass: exit 64, clear message |
| N-431 | Pass: CI4 `/backup/restore` GET/POST/PUT/DELETE/PATCH/OPTIONS/HEAD and 5 MB upload → 404, sign-in off and on; unknown Host → 421; legacy unchanged |
| N-432 | Pass: unknown `APOTHEKE_APP` → 64; legacy keeps `sess_*`, writes no `.ci4`; old deadline names ignored |
| Suites (8.4) | CI4 sqlite 801 OK / pgsql 801 OK; legacy sqlite 801 OK / pgsql 801 OK; CI4 module 651 OK |
| Legacy DSN precedence | Script golden rows pass on head 8.4 and base 8.3 (see L-435) |
| Lint / shellcheck / gitleaks `996a05d..7942fc7` and `ad343bf..7942fc7` / toml sha | Pass / clean / clean / `b5bc9f98…` |
| Cloudflare phase | Delta adds no statics, superglobals or `getenv` |
| N-433 | Deferred in HANDOFF (item 5) |
| L-435 fix `cd15df1` | Pass: CHANGELOG +3 / HANDOFF +4 only; both upgrade notes accurate; [2.6.22] and older byte-identical to main; N-436 to N-438 deferred (HANDOFF item 6) |

## Security

**L-430 hardening (fixed).** Verified in the started container itself:

- A link at `images`, `thumbs`, `originals`, `originals/map` or `sessions` (to `/etc`, `conf.d`, `/proc/sys/kernel`, dangling) → exit 78, `…must be a directory, not a symbolic link`, target not created; legacy and ci4.
- A link in place of the `.ci4` marker (root and `-u www-data` starts): no target created, `.ci4` becomes a www-data plain file, `sess_*` pruned once.
- Nested links (`images/a`→`/etc`, `images/b`→`/etc/shadow`), `sess_*` links and `catalog.sqlite`→`/etc/shadow`: targets keep root ownership; `sess_*` links not followed or deleted. A root-owned regular file in `/data` is still handed to www-data.
- Audit of every root-run step: the check loop, `mkdir -p`, `find … chown -h`, `chmod 0770 sessions`, marker (www-data), `nginx.conf` (rootfs), `/run/apotheke-nginx` (root 0755; body/fastcgi www-data 0700). Only the path-based race below remains.
- Hard links: `protected_hardlinks=1` in the container and on the host, and `/data` is a separate mount (`ln` from the rootfs → cross-device). No finding.
- A `.ci4` directory blocks the prune (www-data can already end its own sessions); benign.
- A full start with `-u www-data` fails at the `nginx.conf` write (same on base; not new).

**Legacy DSN precedence.** The text in `configuration.md` and the CHANGELOG is accurate. It is a change for existing 8.3 installs: against base 8.3, cases a, c, d, e, e2 and e3 differ. With both DSN and `DB_USER`/`DB_PASSWORD` set, legacy now connects as the DSN role. `user=`/`password=` text in another setting now fails to connect.

**8.3 refusal path.** It is no longer built in CI; only the `DockerfileTest` regex covers it. 8.3 isn't shipped and the manual check passes, so the risk is low (N-438).

### Mutants

| # | Change | Result |
|---|---|---|
| Mp3 a/b/c | old names via `$_ENV`, `$_SERVER`, `getenv` | Killed (unit) |
| Me5 | unknown `APOTHEKE_APP` served as legacy | Killed (signin-checks) |
| Me8 | prune in legacy mode too | Killed (signin-checks, legacy) |
| Mc1 | no symlink check | Killed (unit + runtime) |
| Mc2 | marker as root, link followed | Killed (unit + ci4 runtime) |
| Mc3 | `chown` without `-h` | Killed by unit regex only (N-438) |

A racing www-data container that swapped a parent directory for a link during 15 starts escaped 0 times.

## Findings (IDs from 435)

| ID | Sev | Note |
|---|---|---|
| L-435 | L (fixed in `cd15df1`) | The CHANGELOG `### Upgrade notes` has no entry for two default-image changes that hit existing legacy installs: (1) PHP 8.4 `DB_DSN` credential precedence (DSN role can now win; some DSNs now fail); (2) a symlinked data directory now refuses to start (exit 78), with `ORIGINALS_DIR` (or mounting the real directory) as the supported way. Add both as upgrade notes. Doc-only. |
| N-436 | N | The exit-78 message could name the supported alternative (`ORIGINALS_DIR`, or mount the real directory). |
| N-437 | N | Residual hardening: the check, `mkdir`/`chmod` and `find`→`chown -h` steps are path-based. A www-data process running on the same volume during start-up could swap a directory for a link in between, and the batched `-exec … +` widens the window. Not reproduced, and it needs a concurrent writer at start. Defer, e.g. by skipping the walk when the top-level dirs already belong to www-data. |
| N-438 | N | `chown -h` and the 8.3 refusal path are covered only by `DockerfileTest` regexes; a runtime case with a root-owned link would kill Mc3. Optional. |

No H/M. L-430, N-431, N-432 and L-435 are resolved; N-433 and N-436 to N-438 are deferred.

## Verdict

**PASS** at `cd15df1`. The L-430 fix holds against every variant, N-431 and N-432 are fixed with the mutants killed, legacy and CI4 are green on 8.4.27, and the L-435 upgrade notes are accurate. N-436 to N-438 are deferred.
