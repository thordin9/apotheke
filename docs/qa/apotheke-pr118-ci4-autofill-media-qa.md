# QA: PR #118 (CI4 step 12, autofill reads stored photos through Media)

Head `c08fd42a6bd0b37f80982f038b4628eda9bfba56`, base feature/ci4 `68aaa86` (tip and merge-base). CI4 on PHP 8.4, legacy legs on PHP 8.3, SQLite and PostgreSQL 17.
Scope: stored-photo reads for autofill go through `ImageStore`, compared byte for byte with legacy.

## Checklist

| Area | Result |
|---|---|
| Lint, deptrac (0 errors), DI check | PASS |
| gitleaks `68aaa86..head` (2 commits); `.gitleaks.toml` sha b5bc9f98…3a8f unchanged | PASS, no leaks |
| CI4 unit SQLite / PostgreSQL 17 | 455 / 580 OK (2 / 0 skipped) |
| CI4 HTTP suite SQLite / PostgreSQL 17 | 715 / 715 OK (39 / 6 skipped, environment) |
| Legacy legs, PHP 8.3, SQLite / PostgreSQL 17 | 715 / 715 OK |
| `HardeningHttpTest` on CI4, 3x per driver | PASS; no flake seen |
| Merge commit remerge-diff | empty |
| Playwright | not run, no UI change |
| Mutation, 9 mutants | PR suite 3/9; QA harness 8/9 (1 equivalent) |
| Photo requirement, offline and live | holds |
| Live check 1 (3 calls) | PASS, 11.75 Neurons |

## Verification

- **Byte parity, fake transport.** A recording stub compared legacy and CI4 on both drivers.
  - Inputs: EXIF-rotated JPEG, PNG, HEIC fixture, 6000x4000, 320x240.
  - The sent image, headers, multipart context and response are identical between the apps for every input.
  - Every sent copy is a JPEG of at most 1024 px with no EXIF or GPS. Rotation is applied.
- **Photo requirement.** For every input, the original on disk matches the upload's sha256 and is unchanged after a suggest. The served copy is unchanged too, and the sent copy differs from both.
- **Missing and broken files.** Same outcome in both apps:
  - A corrupt original gives `unsupported`.
  - A missing original falls back to the served copy.
  - A corrupt served file with no original gives `unsupported`.
  - An empty served file, or neither file, gives `no_image`.
- **Ids and paths.** Unknown, non-numeric, negative, exponent and padded ids all give `no_image`. So do stored names that are absolute, traversing, NUL or upper-case.
- **Order.** Autofill off gives `off` whether or not the photo exists. No URL gives `off`. A missing token gives `unauthorized`.
- **Path checks.** CI4 storage checks are now stricter than legacy (N-271).
- **Cloudflare phase.** All stored-photo reads go through `ImageStore`. The only direct file reads left are the upload temp file, the bundled sample and the system ICC profile, all from before this PR. There is no new static or per-request state.

## Live check 1

Run 2026-10-26, 11:04–11:05 EET. Throwaway CI4 install at the head, sign-in off, fresh SQLite, autofill pointed at a QA recording proxy in front of the Worker (https://autofill.example). The proxy recorded sizes, dimensions, status and timing, never the token.
Each call was made once, with no retries.

| # | Call | Upstream | Result |
|---|---|---|---|
| 1 | Control Panel Test | `/healthz` 200 (187 ms), `/v1/info` 200 (172 ms) | ok, 0 Neurons |
| 2 | Test with the sample photo | 1 `/v1/suggest` 200 (2.1 s), JPEG 800x363, 21,866 B, no EXIF | ok, suggestions |
| 3 | `/save` with a 1920x1440 photo (1,001,245 B), then suggest by `photo_id` | 1 `/v1/suggest` 200 (6.6 s), JPEG 1024x768, 166,738 B, no EXIF | ok, suggestions |
| 4 | Optional image-file suggest | skipped, since (2)+(3) were not clearly under 15 Neurons | n/a |

- Step 3: the stored original is byte-identical to the upload, before and after the suggest. The served copy is unchanged. The sent copy is neither the original nor the served copy. It matches, byte for byte, what the same flow sent to the fake transport.
- Saving the token makes one extra 0-Neuron `/v1/info` check, which was used as the before reading.
- **Neurons.** Cloudflare's usage analytics were not available to the provided token (not authorized for the account), so the Worker's own budget counter was used. It read 0 before and 11.75 after: 11.75 Neurons for calls 2 and 3, under the 25 cap.

## Mutation

| Mutant | PR suite | QA harness |
|---|---|---|
| Served copy read before the original | killed | killed |
| No fallback to the served copy | killed | killed |
| Name validation dropped | survived | survived (equivalent: storage rejects the same names) |
| Served-exists check dropped | survived | killed |
| Off check moved before the lookup | survived | killed |
| Thumbnail fallback | killed | killed |
| Lenient id parse | survived | killed |
| Original only | survived | killed |
| Empty-bytes check dropped | survived | killed |

## Findings

| ID | Grade | Finding |
|---|---|---|
| L-270 | L | With `allow_url_fopen=Off`, a suggest by `photo_id` sends EXIF-rotated photos un-rotated. Legacy and the base keep the orientation. Hardening: give the analysis builder the EXIF orientation without a `data:` stream (for example a temp path, or parsing EXIF from the bytes). |
| N-271 | N | Info: CI4 storage path checks are stricter than legacy for stored photo names. This matches how Media serves files. Hardening: note it in the migration notes. |
| N-272 | N | Test gap: the PR suite misses 5 of 9 mutants (served-exists check, off-check order, id parse, original-only, empty bytes). Hardening: add tests like the QA harness cases. |
| N-273 | N | Info: the stored original is read in full before the autofill-off check. Hardening: check the setting first. |

No High or Medium findings.

## Security

- **Privacy.** Only a resized copy with metadata stripped leaves the install. This was checked offline for every input and live through the proxy. Originals are never changed.
- **Paths.** Unknown or malformed ids and unsafe stored names never reach a file read outside storage. CI4 is at least as strict as legacy.
- **Secrets.** Nothing secret went into the logs, the proxy records or this doc. gitleaks is clean and the config is unchanged.

## Verdict

**PASS (0H/0M/1L/3N).** L-270 and N-271 to N-273 can go into a follow-up PR. Commit this doc byte-for-byte as the last commit.
