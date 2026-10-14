# AI autofill (optional)

Apotheke can ask a separate **autofill service** to suggest an item's name, category, tags and
description from its photo. The service is its own project
([thordin9/apotheke-autofill-service](https://github.com/thordin9/apotheke-autofill-service), API v1)
and runs wherever you like: on the same box, a Jetson or a GPU machine on your network.

Autofill is **off by default**. While it is off, Apotheke never contacts the service.

## Setting it up

Control Panel → **AI autofill** (`/settings/autofill`, needs the *Settings* permission – admins only by default):

| Setting | Default | Notes |
|---|---|---|
| Suggest details from the photo | off | Master switch. |
| Service address | empty | Base URL without `/v1`, e.g. `http://192.168.1.20:8090`. Only `http://` and `https://`. |
| Timeout (seconds) | 30 | 3–120. The service answers `504` itself after 25 s, so 30 s is a good value. |
| Access token | none | Only if the service runs with `AUTOFILL_TOKEN`. See below. |

All values live in the `settings` table like every other Control Panel option.

### Test connection

**Test connection** checks the address and timeout *as typed* (nothing is saved) and the stored token:

1. `GET /healthz` (no token) – is anything listening?
2. `GET /v1/info` (with the token) – is it the autofill service, API v1, and does it accept the token?

Results: *Connected* (with name, API version, capabilities, backend and image limit), *could not connect*,
*no answer within N seconds*, *the service refused the token (401)*, *not a valid /v1/info answer* or
*still warming up* (`ready: false`).

### The access token is write-only

- It is saved through its own form and **never shown again**: the page only says whether a token is set.
- It is a `secret` setting: not in the settings export, not in backups, cannot be set by a settings import,
  never written to logs and never sent to the browser. Suggestions go through Apotheke's server, so the
  browser never talks to the service directly.
- **Clear token** removes it.

All forms on the page need the CSRF token; viewers and editors get `403`.
