# PR 271 container sleep after start — evidence

Read-only. Head `be8345de190ef6cc4dafd94c047d74b623970b49` matches `refs/pull/271/head`. Parents: `79a26defdd24e296f649ee0c5c357cb3e7c89ca2` and `0840308c90b31730d553b0e31cee2f94b73f5915` (main, v3.36.2). Behind main: 0. Rechecked live head: unchanged.

`git diff fc7e3aa2 79a26def` and `git diff 0840308c be8345de`, excluding `CHANGELOG.md`, `docs/HANDOFF.md` and `docs/qa`, with `index` lines dropped, differ only in five `@@` hunk headers (line offsets). The diff against main touches 7 files (+128/-18): `edge/src/index.ts`, `edge/tests/container-forward.test.mjs`, `scripts/deploy-scripts.test.mjs`, the changelog, the handoff and the two existing QA docs.

`edge/src/index.ts` at the head: no `monitor` or `waitUntil` call (one comment explains why). `setInactivityTimeout` is called in the constructor inside `blockConcurrencyWhile` when `c.running`, and after `c.start(...)` in `ensureReady`. `ensureReady` returns early when `c.running`. The start env has `APOTHEKE_EDGE_CLIENT_IP: "1"` and the `STORAGE_BACKEND`/`DB_DRIVER` spread.

`git diff 0840308c be8345de -- docs/HANDOFF.md` is one line: main's text plus the #271 sentence. `git diff 0840308c be8345de -- CHANGELOG.md` is one hunk before `## [3.36.2]`.

CI run `43125639967`. D1 job `130876887823` pass. SQLite job `130876887493` and PostgreSQL job `130876887925` pending. No failed check.

H-1849 closed. N-1850 and N-1851 open. Next free id: 1852.
