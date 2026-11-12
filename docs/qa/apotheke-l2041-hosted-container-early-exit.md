# L-1987: hosted preview containers stop with code 0 before the sleep timer

Severity **L** · Opened 2026-11-12 (EET) by DevOps from the QA-1854 previews · Status: **open**, not reproduced on demand.

## Symptom
The container behind an `apotheke-api`-style Worker stops on its own while the Durable Object's inactivity timeout
(`SLEEP_AFTER_MS=900000`) has not run out. The container log shows Caddy's graceful-stop line `exiting; byeee!!` and
then `Container exited (code: 0)`, and the instance lists as inactive. The next request starts a fresh container
(cold start 4-5 s measured); in storage mode the data is intact (read back after a stop). Seen only on throwaway
workers.dev previews; prod `apotheke-api` shows no unexplained early exit.

## Observed (EET, Workers observability on each container application)
| when | preview (container app) | PHP mode | idle before the exit |
|---|---|---|---|
| 15:11:26 | apotheke-workerpreview-20261112 (a039f9c9), main 48d2fbc1 | classic | about 20 s |
| 15:37:28 | same | classic | 21 s (a concurrent isolation run ended 15:37:07) |
| 16:57:20 | same, #292 at e7993127 | worker | 20 s (a concurrent isolation run ended 16:57:00) |
| 18:20:51.601 | apotheke-rerun-c-20261112 (a0375fd7), #292 at 7ea981ff | classic | 11 min 51 s |
| 18:20:51.415 | apotheke-rerun-w-20261112 (a030192e), #292 at 7ea981ff | worker | 11 min 51 s |
| 19:25:13.588 | apotheke-pra-20261112 (a03094e6), #297 at f0eefef6 | worker | 20.9 s (last request 19:24:52.683, an isolation run) |
| 19:48:04.532 | same | worker | 13 min 43.5 s (823.5 s; last request 19:34:21.029) |

- The two 18:20:51 exits are two separate container applications, one per PHP mode, stopping 0.2 s apart. In the two
  minutes before, neither container logged anything but the stop lines, and neither Worker logged a request.
- On the #297 preview (N-1986 recheck) both stops came early: one 20.9 s after an isolation run, one 76.5 s before
  the 900 s timer. Each was followed by a clean cold start (container-start 5.9 s and 6.3 s) with data intact.
- Normal sleeps on the same previews come after about 14.5-15 min idle (for example a039f9c9 at 17:15:17).
- Not reproduced on demand: 8 sequential bench batches (17:28-18:05) and a 3-minute, 6-thread concurrent burst on
  both rerun previews (18:05:50-18:09:00) were followed by no exit within 70 s.

**N-1986: accepted as platform behaviour (PM accepted 2026-11-12).** The #297 worker-mode preview stopped 20.9 s and
823.5 s after the last request, exit code 0 both times, with cold starts of 5.9 s and 6.3 s and data intact. This matches
this ticket and does not block turning worker mode on.

## Prod `apotheke-api` (read-only check, container app a03eab6a, 10:22-18:35 EET)
15 exits, all code 0. 10 after 881-898 s idle (the 15-minute sleep). 5 shortly after traffic, each after a release
deploy rolled the container: 12:55:22 (deploy 12:54:27), 14:57:52 (14:57:15), 15:51:54 (15:51:17), 16:59:27
(16:57:41), 18:02:15 (17:56:43, v3.36.10). None at the preview exit times.

## Hypothesis
A platform-side stop (host maintenance, rebalancing or a Containers rollout) sends SIGTERM; code 0 is FrankenPHP's
clean shutdown under tini. Two applications stopping in the same second, in both PHP modes, points there. Not
indicated: worker mode (classic stops the same way), app errors (no error lines before the stops), memory (steady
at 175-185 MiB, and code 0 rather than 137). A Durable Object reset that takes the container with it is not ruled out.

## Next steps
- On the next preview, pull the container instance events for the app (dashboard or API) around any early exit, or
  give Cloudflare support the app ids and timestamps above.
- Log a line from the `ApothekeContainer` constructor in `edge/src/index.ts` so a Durable Object restart shows next to
  a container stop.
- Watch prod for an exit with under 800 s idle and no deploy in the 10 minutes before.
