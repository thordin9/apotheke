# QA: apotheke #201, tighten asset caching and release checks (static frontend)

**Gate:** light (diff read, CI and the cheap local checks; no full e2e; targeted mutations). **Head:** `305eb91971996df0881a4ca7c3cc692b3dc09ecb`, first reviewed at `2f09b09`. The delta is two commits, `e9b3443` and `305eb91`, touching `_headers`, `check-dist.mjs`, `check-dist.test.mjs`, `frontend/README.md` and `docs/deployment-and-operations.md`. It is 0 behind main (`3baa3dc`, v3.9.0), mergeable and clean, +706/-49 across 11 files. **Reviewer:** Apotheke QA 2.
**Verdict: PASS at `305eb91`.** No Highs or Mediums. M-1533, L-1534 and N-1535 are fixed. L-1536 and N-1537 are new and optional; they don't block.

## Checklist

- [x] **CI.** 23 check runs are green at `305eb91`, CodeQL and Conventional Commits included. Deploy getapotheke-app and Deploy apotheke-api are skipped, as usual on a PR.
- [x] **Local run.** `npm ci --ignore-scripts`, `npm run build` and `check-dist.mjs` pass. `node --test` on the check-dist, app-fallback, consent and deploy-scripts tests passes 37/37.
- [x] **Cloudflare headers.** Under `wrangler dev` 4.156.0, all 8 `_headers` rules parse with no "Skipping" warning. The hashed JS and CSS are `public, max-age=31536000, immutable`. `/assets/missing.js` and `.css` are a 404 with `no-cache`. Brand, mascot, shots and `icons.svg` are one day, and `/` and `/privacy/` are `no-cache`.
- [x] **Off-site checks on a real build.** Every case from the first pass now fails check-dist. Local images, local CSS URLs and outbound `<a href>` links still pass.
- [x] **Unchanged since the first pass.** The CSP lines, N-1417, N-1418, N-1510 and N-1532.
- [x] **Commits and PR text.** `fix(frontend)` and `test(frontend)` subjects are neutral. The PR body has no secrets and names no host other than this site. The tests use `.example` hosts only.
- [x] **Mutations.** 9 of 10 fix reverts are caught:
  - Wildcard count check removed, and the `*` plus `:splat` check removed: both caught by the unit test.
  - Placeholder support removed: caught by 2 unit tests.
  - srcset scanned for its first candidate only: caught by the unit test.
  - http and `//` handling removed from the host parser: caught by 3 unit tests.
  - `resolves()` accepting `http:` again: caught by the unit test.
  - CSS escape decoding off, and HTML entity decoding off: both caught by the unit test.
  - Survived: the placeholder regex widened from `[^/]+` to `.*` (N-1537).

## Findings

- **M-1533: fixed in `e9b3443`.** `_headers:29,33` use `/assets/:name-:hash.js` and `.css`. `parseHeaders` rejects more than one `*`, and `*` with `:splat`. It matches a placeholder as `[^/]+` (`check-dist.mjs:46,53-97`). The unit test covers the rejection and the match. The wording in `docs/deployment-and-operations.md:639-642` and `frontend/README.md:90-92` is accurate.
- **L-1534: fixed in `305eb91` (hardening).** The off-site check is stricter. There is a unit case for each form, and `resolves()` rejects `http:`.
- **N-1535: fixed in `e9b3443`.** The `_headers` comment (lines 22-28) and both docs say that a missing hyphenated name is an immutable 404, which is accepted.
- **L-1536: new, optional (hardening).** A few more URL forms are worth covering in a follow-up. The CSP still blocks them in the browser. The PM has the details.
- **N-1537: new, optional.** No test pins that a placeholder stays within one path segment, so widening it to `.*` passes. Fix: add a case to the unit test asserting that `/assets/a/b-c.js` is not immutable.

## Conditions

Squash-merge with the PR title as the subject. This doc must be the last commit, byte-exact. No secrets, tokens or credentials appear in the diff, the tests or this doc.
