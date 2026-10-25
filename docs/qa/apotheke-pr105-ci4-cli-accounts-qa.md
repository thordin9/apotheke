# QA: apotheke #105, CI4 CLI account forms (step 7 B, part 2)

- **PR:** [#105](https://github.com/thordin9/apotheke/pull/105) (`ci4/auth-cli` into `feature/ci4`). Security gate for `apotheke:accounts` / `bin/settings.php` account forms.
- **Head:** `d3ca0a7353cdf6a966b41d736a1e0d1e78aab7c2`. Prior FAIL head `4713dc3`; fix delta `47f88ee` + `d3ca0a7`. `feature/ci4` unchanged at `05613f5` (#103).
- **Verdict: PASS at `d3ca0a7353cdf6a966b41d736a1e0d1e78aab7c2`.** 0 High, 0 Medium; 0 new Low; 1 Nit (SIGTERM). M-130 and N-131 closed. L-110 still open from #104.

## Checklist

| Check | Result |
|---|---|
| CI | 22/22, as reported by Coder2 (run `40239831655`). The GitHub API isn't reachable from here. |
| M-130 fix | `askPassword` wraps both prompts in `withEchoOff()`: `stty -g` (regex-checked), `stty -echo` once, restore in `finally`. `stty` via `proc_open(['stty', $arg], [0 => $stdin, …])` — no shell. Real pty: success, mismatch, ^C (exit 130) restore echo; secrets never in `shown`. Piped stdin unchanged; stdin-pipe + TTY stdout OK. |
| Signals | With pcntl: SIGINT handler restores, prints newline, exits 130; prior handler and async-signals restored. Without pcntl: finally covers success/mismatch; ^C ends PHP (documented). **SIGTERM leaves `-echo` (N-133).** |
| Injection | `stty -g` must match `^[0-9A-Za-z:=-]+$` before restore; `;` / space / `/` rejected. Array `proc_open` — no shell even if the pattern slipped. |
| Pty test | `testPasswordPromptsOnATerminal` + `tests/fixtures/pty-run.py`: success, mismatch, ^C. Skips locally without python3/pty/pcntl; `assertFalse(getenv('CI'))` first so CI fails instead of skip. 3× local runs stable. |
| Mutations (delta) | A03 (no `-echo`), A12 (no finally restore), A14 (SIGINT without restore) killed by the pty test. A13 (skip regex) and A15 (`shell_exec` instead of `proc_open`) survive: equivalent here (harness makes stdin the controlling tty). Author’s 8 reported killed — not re-listed. |
| Routing / first-admin / last-admin / roles / exits / epoch | Unchanged from the `4713dc3` gate; parity goldens still green on SQLite and PG17. |
| Worker / Cloudflare | Nothing blocks FrankenPHP worker / Cloudflare Container phase. |
| Tests | CI4 module: SQLite 431 (1 skipped), PG17 546 OK. `Ci4AccountsCliTest` (5 tests, 1407 asserts) OK on SQLite and PG17. |
| Gates (delta) | `.gitleaks.toml` `b5bc9f98…` unchanged; gitleaks on `4713dc3..d3ca0a7` clean. `check-commits` ok (2). `lint.sh` OK. Markdown links 0 broken. |

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| M-130 | Medium | **Closed** | Terminal echo restored after interactive password (save/`stty -g` + `proc_open` on stdin + finally; pty test). Prior `shell_exec('stty echo')` did not reliably target the command’s stdin. |
| N-131 | Nit, tests | **Closed** | Pty coverage for hide + restore + ^C. |
| N-133 | Nit | Open | No `SIGTERM` handler. `kill -TERM` during a prompt leaves the TTY in `-echo` (finally does not run). Optional: mirror the SIGINT restore path. `SIGKILL` cannot be handled. |
| N-111 | Nit | Closed | Pending-admin demote covered. |
| N-132 | Nit | Open | `resetBuiltin` transaction still untested by mutants (from first gate). |
| L-110 | Low, legacy too | Still open (#104) | Last-admin check not atomic across CLI/web. |

## Security

- **TTY / secrets:** typing hidden; state restored after success, mismatch, exception, and Ctrl-C (pcntl). Password not on stdout/stderr/logs in probes. Argv passwords remain (legacy).
- **stty:** no shell; restore argument regex-gated.
- **Authorization / sessions / deviations:** as in the first gate (CLI always admin; epoch on password change; WriteFailed/NUL exit 1; recovery non-transactional).
- **Backlog:** L-110; optional N-133.
- **Worker phase:** nothing blocks FrankenPHP / Cloudflare Containers.

**Verdict: PASS at `d3ca0a7353cdf6a966b41d736a1e0d1e78aab7c2`** (0H / 0M / 0 new L / 1N).
