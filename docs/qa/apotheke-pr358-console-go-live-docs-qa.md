# QA: apotheke #358, who runs the operator console go-live

- PR: https://github.com/thordin9/apotheke/pull/358 (DevOps), base `main`. Docs only: the *Going live* section of `docs/operator-console.md` says the team (DevOps) runs the steps on the owner's go-ahead and the PM approves the `deploy-edge` review; the step 5 fail-closed check now covers `/` and `/api/ops/v1`.
- Reviewed head: `d24982ae99537944a24c513d7c3075a271daad30`, 0 behind main `7a45c1ca` (v3.65.1), merge state `CLEAN`. Content gated at `01b9de89` (0 behind `c5476e79`). Commits: `816f8ef5` (docs), `01b9de89` (CHANGELOG link), `bb737ab1` (the earlier QA doc, replaced by this one), merges `1069c9b8` (onto v3.65.0) and `d24982ae` (onto v3.65.1). Each merge differs from `git merge-tree` only in `CHANGELOG.md` (markers dropped, the #358 bullet once in `[Unreleased]`); the `docs/operator-console.md` hunks are identical to `01b9de89`; `VERSION` is 3.65.1, equal to main.
- Gate: light (docs only). Diff read, consistency grep across `docs/`, `deploy-edge` environment rules read, placeholder and secret check. No suites, mutants or Docker runs.
- CI on `d24982ae`: 21/21 green. CI on `01b9de89`: 21/21 green, both required checks pass.
- Reviewed 2026-11-21 08:28 to 08:33 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope (`main...head`): 2 files, +11/−5: `docs/operator-console.md` and `CHANGELOG.md`. No code, workflow or config change.
- [x] Steps 1 to 5 keep the same order and content; only the actor line, the one-time PIN wording and the step 5 check change. Step 5 still checks `writes_enabled:false` and a 403 `forbidden` for any other email.
- [x] Placeholders only: `<operator email>` and the placeholder host. No real email, Access AUD, account or D1 id, token or secret in the diff.
- [x] CHANGELOG: one bullet under `[Unreleased]` / `### Maintenance` (a heading already used in released sections), linking the full PR URL. Released sections and `VERSION` (3.64.0) are unchanged.
- [x] Commit subjects follow Conventional Commits and are within 72 characters.
- [x] `deploy-edge` still has a required reviewer and a branch policy; the doc's go-ahead line doesn't change either.

## Findings

- **L-2552:** `docs/deployment-and-operations.md:889` still says the `deploy-edge` review is approved by Stathis (the storage-gate steps), and the table rows 1.2 to 1.9 name him as the actor. The new line says the PM approves the `deploy-edge` review. The two docs now disagree on who approves that same environment. Optional: scope the new sentence to the console go-live, or align line 889 in a follow-up. Non-blocking.

## Verdict

**PASS** at `d24982ae`, 0 behind main `7a45c1ca` (v3.65.1). This clear is only for the current main; after any other PR merges, #358 needs a plain catch-up, a merge-only read and a reissued doc. The doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #358"), followed by the hash check and green CI on that head. Squash as "docs(console): say the team runs go-live on the owner go-ahead (#358)". As `docs`, this cuts no release.
