# PR 264 container forwarding — QA

**Verdict: PASS** at `301540e15d9afaa1cf775e1bcc55bac54fa7d03b`. H-1847 is closed. A cold start probes a host the allow-list accepts, and client requests still go through the same allow-list.

Branch `fix/edge-container-forwarding`. Base `main` `4dd89b73ad82c9ee4d872b5ef5cf0a48e07deab3`. Behind 0. Ahead 4. The range since `13ce2afd` is `5323421c` and `301540e1`: 5 files, +108/−3. CI: 23 passed, 2 skipped (the two deploys), 0 failed.

## Checklist

| Check | Result |
|---|---|
| Readiness | The probe is a new request built from `ALLOWED_HOSTS` on the Worker env, then `toContainer`. It is not `http://container/healthz`. A 200 ends the wait. The simulated cold start returns 200, not 503. |
| Probe host | The first list entry is used, `*` is skipped, and an empty list uses `127.0.0.1` and logs that. The client request is not an input. |
| Allow-list | `HostAllowList` was not in this range. `container` and `evil.example` are refused when the list is one public name. `127.0.0.1`, `localhost`, and that public name (any case) are accepted. A configured `*` still accepts any name; this change does not add `*`. |
| Forwarding | Unchanged from the `13ce2afd` review: `http`, `redirect: manual`, forwarded proto and host overwritten from the URL, 302 not followed. |

## Findings

H-1847 closed. No new finding. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1849.

## Security

The probe cannot be pointed at a host by the request. `Host: container` is not sent. An unlisted client `Host` is still rejected by the app with 421. The Worker does not add names to the allow-list.

## Tests

Readiness and forward tests, loaded the way the edge suite loads them: 5 passed. Forcing the probe URL to `https://container/healthz` failed the first-host test. Restored. `HostAllowList` checks above were run locally.

## Verdict

PASS. `EDGE_STORAGE_READY` stays off.
