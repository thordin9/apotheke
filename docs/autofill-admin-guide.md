# AI autofill: admin guide

This guide is for whoever runs Apotheke and its [autofill service](https://github.com/thordin9/apotheke-autofill-service).
It covers the settings under **Control Panel → AI autofill** (`/settings/autofill`), the two test buttons and what
changed in Apotheke 2.4. The technical reference (every field, message and limit) is [AI autofill](autofill.md); what
people see on the item form is in the [user guide](user-guide.md#suggestions-from-a-photo-ai-autofill).

You need the *Settings* permission (admins by default).

## 1. Connect the service

1. Switch on **Suggest details from the photo**.
2. **Service address**: where the service runs, without `/v1`, e.g. `http://192.168.1.10:8080`. If Apotheke runs in
   Docker, `localhost` is the container itself: use the machine's LAN address.
3. **Timeout (seconds)**: about 5 s more than the service's own timeout. The default 50 fits the shipped Jetson
   setup (the service gives up after 45 s).
4. Press **Test connection** (nothing is saved). *Connected* lists the service's name, API version, what it
   suggests, its image limit, its typical answer time and, for a service with API v1.2, its **models** and **image
   sizes**.
5. If the service runs with `AUTOFILL_TOKEN`, enter it under **Access token** and press **Save token**. The token is
   write-only: it is never shown again, exported or backed up.
6. Press **Save**. Apotheke fetches the service's details once (at most 5 s), so the first photo already gets a
   progress estimate.

## 2. Analyze photos automatically (off by default)

| Setting | What happens on the item form |
|---|---|
| **Off** (default, recommended) | Nothing is sent when someone takes or picks a photo. Each photo gets an **Analyze** button; the photo goes to the service only when someone taps it. |
| **On** | Every photo picked on the item form is sent to the service right away (the Apotheke 2.3 behaviour). The Analyze buttons stay, for a second try. |

Off is the default on **every** install, new and upgraded: photos are only sent when someone asks for it, and the
service is not kept busy with photos nobody needed suggestions for.

## 3. Model and image size

Both dropdowns appear only when the service offers a choice (API v1.2: `models` and `image_sizes` in its
`/v1/info`). They are filled from the last answer of the saved service, or from a **Test** you just ran.

| The service's `/v1/info` | Model dropdown | Image size dropdown |
|---|---|---|
| no `models` / no `image_sizes` (older service) | hidden; no model is requested | hidden; photos go at **1024 px**, as in 2.3 |
| `models` with **one** entry (`ALLOWED_MODELS` unset on the service) | shown with that model, **disabled**, with the hint *More models appear when ALLOWED_MODELS is set on the service.* | – |
| `models` with two or more entries | enabled | – |
| `image_sizes` listed | – | enabled; *Recommended* preselected |

**Why 1024 px for an older service:** 2.3 already resized every photo to a 1024 px long edge (in the browser and
again on the server), so an older service gets exactly what it got before (never enlarged). A v1.2 service decides
through its recommended size instead.

**The Jetson setup** (from the service benchmarks): the service runs with `IMAGE_SIZES=448,560*,1024` and
`ALLOWED_MODELS` unset, so Image size shows *Recommended (560 px)*, 448, 560 and 1024 px. The Model dropdown shows
the one installed model, disabled, as long as the service lists it in `models` (the dropdown stays hidden if the
service sends no `models` at all).

- **Model**: *Service default (name)* lets the service decide (recommended until you have compared models), or pick
  one of the installed models it allows. Each entry shows *loaded* (in memory right now) and its typical time, when the
  service reports them. Switching to a model that is not loaded makes the **next** photo slow (the service loads it
  first; the form says *Model is warming up… retrying in N s* and retries once). A model the service does not know
  falls back to its default; Test with a photo then shows *Model used: X (asked for Y)*.
- **Image size**: *Recommended (N px)* uses the size the service recommends, or pick a long edge. Smaller images are
  analyzed faster; larger ones can read small print better. Photos are resized on the phone or computer before upload
  and are **never enlarged**.

The progress bar on the item form estimates with the chosen model's own typical time.

Defaults: until you choose, Apotheke uses the service's default model and recommended size. Which model and size suit
your hardware best is measured in the service's benchmarks (`docs/benchmarks.md` in the service repository); set the
service's defaults from there, and leave these two on *Service default* / *Recommended* unless you want something
else for this Apotheke.

## 4. The test buttons

Both use the values **as currently typed** (address, timeout, model, image size) and save nothing, so you can try a
model or a new address before saving.

- **Test connection**: is the service reachable, is it the autofill service, does it accept the token, is its model
  ready. Shows its details (see step 1).
- **Test with a photo**: the same checks, then a bundled sample photo (a claw hammer) through the full suggestion
  step, with your real categories and tags. Shows three checks (*Reachable*, *Token accepted*, *Model ready*), the
  suggested name, category, tags and description with their confidence, **Model used**, the **Image size** sent and
  how long it took. It can take as long as the timeout, e.g. on the first photo after a model switch.

Typical results and what to do:

| Result | Fix |
|---|---|
| *Could not connect* | Wrong address or port, the service is not running, or a firewall is in the way. |
| *No answer within N seconds* | The service is overloaded, or the timeout is too short. |
| *The service requires a token* / *refused the token* | Save the token the service expects, or clear it. |
| *Still warming up* / *Model not ready* | The service is loading its model; try again in a minute. |
| *Model used: X (asked for Y)* | Model Y is not allowed or not installed on the service; pick another or ask whoever runs the service. |

## 5. Upgrading from 2.3

- After the upgrade (migration 0025), **Analyze photos automatically is off**, even if autofill was on before:
  people tap **Analyze** on a photo to get suggestions.
- **To turn auto-analyze back on**: Control Panel → AI autofill → switch on **Analyze photos automatically** →
  **Save**.
- Model and Image size start at *Service default* / *Recommended*. Once the service is updated to API v1.2, press
  **Test connection** (or Save) to see the dropdowns.
- A second analysis of an item (another photo) no longer replaces what an earlier one filled in; it only fills
  fields that are still empty.

## Security notes

- Photos go from the browser to Apotheke, and from Apotheke's server to the service; the browser never talks to the
  service and never sees the address or the token.
- Test connection can make this server contact any http(s) host an admin types in. Grant the *Settings* permission
  only to people you trust with that.
- Problems are logged as one short line (`apotheke autofill: …`), never with the token or the service's answer.
