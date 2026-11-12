# QA: apotheke #281, re-check readiness after a timed-out container start

- PR: https://github.com/thordin9/apotheke/pull/281 (Coder), base `main`. It replaces https://github.com/thordin9/apotheke/pull/280, which was closed so that no pushed history had to be rewritten; the code was fully gated on #280.
- Reviewed head (on #280): `f7a0fae8417ceb6d7a7e2c5ab580d8851d32a192`, 0 behind main `0840308c`. Commits: `c50a53ea` fix(edge): re-check readiness after a timed-out container start, `fbfad877` docs(changelog): note the edge readiness fix, `f7a0fae8` plain merge of `fix/container-sleep`.
- Gate: full (diff read, edge suite on Node 24, typecheck, new tests against the old Worker, 16 mutants, concurrency and error-body probes)
- Reviewer: Apotheke QA 2
- Merge-only read at `8612a2818046a52e4f3b0fb201a3df0e70f4e6b4`, 0 behind main `0297baa3` (v3.36.3): plain merges of main `d16c85e0` (#271, at `f2d275fe`) and `0297baa3` (at `41041e6b`), then `8612a281` docs(changelog), which only drops an empty duplicate `### Fixed` heading the second merge left in CHANGELOG. The edge code and test +/- lines match `76fa3f08...f7a0fae8`, and `edge/src/index.ts`, `edge/tests/container-forward.test.mjs` and `edge/tests/r2.test.mjs` are byte-identical at `76fa3f08` and `d16c85e0`. The CHANGELOG diff against main is only `### Fixed` plus the bullet under [Unreleased]; the released sections are unchanged.

## Checklist

- [x] Scope against the base is 4 files: `edge/src/index.ts`, the new `edge/tests/container-ready.test.mjs`, one import line in `edge/tests/r2.test.mjs`, one CHANGELOG bullet. Against main, the only other files are #271's own. No unexpected files.
- [x] Both 503 paths (a failed or timed-out start, a failed forward) go through one `unavailable()` with `Retry-After: 5`, `Cache-Control: no-store` and the fixed sentence. Reasons go only to `console.error`.
- [x] No wedge or endless loop: each readiness wait is bounded by `READY_TIMEOUT_MS` and ends early if the container stops. A timed-out start leaves `ready` false, so the next request probes again without a second `start()`. A failed forward resets `ready`. A container already running when the DO starts is taken as ready and corrected by the forward path if it isn't listening.
- [x] No module-level mutable state. The DO instance keeps one boolean, `ready`.
- [x] The inactivity timeout is set on every start path: in the constructor for a running container, and in `ensureReady()` for any container that isn't ready, including one this DO didn't start. It is idempotent, so the repeat calls are harmless (see L-1911).
- [x] 90 s: no doc states the old 30 s edge readiness wait. The constructor's `blockConcurrencyWhile` doesn't cover the probe loop.
- [x] Concurrency probe: three cold requests at once give one `start()` and three 200s.
- [x] Tests: the edge suite passes (71/71), and so does CI's full command, deploy-scripts plus edge (104/104). `tsc --noEmit` is clean. Against #271's `index.ts`, 5 of the 6 new tests fail. The one that passes is the constructor-only guard.
- [x] Mutations: 14 of 16 killed, with the 2 survivors noted under L-1913 and N-1914. Killed: dropping Retry-After, 502 or 200 instead of 503, Retry-After 60, skipping the re-probe, no `ready` reset on a failed forward, rethrowing a failed forward, the timeout only for started containers or not at all, 30 s, an inverted readiness check, `ready` not set on a 200, the constructor not marking ready, and an unconditional `start()`.
- [x] CHANGELOG: one Fixed bullet under [Unreleased], linked to #280. Commit subjects are Conventional Commits, and CI run 43126204967 plus the Deploy edge check are green.

## Findings

- **L-1911:** concurrent requests during a start each run their own readiness loop (10 probes/s each) and their own `setInactivityTimeout` call. In the probe, 3 requests made 12 `/healthz` calls. A burst multiplies the probe load on a booting container. Hardening: share one in-flight readiness promise on the DO instance.
- **L-1912:** a container that stays running but never answers `/healthz` with 200 is never stopped or restarted. Every request waits the full 90 s before its 503, with no back-off, until the container exits or the DO restarts. Hardening: after a timed-out wait, `destroy()` and start fresh, or use a shorter wait for the requests that follow.
- **L-1913:** no test pins the fixed body on the new forward-failure 503 (plan 1A.6). A mutant that returns the error text survives. The code is right today. Add a body assertion.
- **N-1914:** the 90 s value isn't pinned. The test waits 35 s, so a 40 s mutant survives. A test near 90 s would hold it.
- **N-1915:** the edge paragraph in `docs/HANDOFF.md` still describes only #271's start behaviour. One sentence on the 503, the re-probe and the 90 s would keep it current.
- **N-1916:** the CHANGELOG bullet links #280, which is closed unmerged; the change lands as #281. Kept on purpose; accepted.

0 High, 0 Medium, 3 Low, 3 Nit.

## Verdict

**PASS** at `f7a0fae8`, confirmed on #281 by the merge-only read at `8612a281`. Squash-merge with the PR title as the subject.
