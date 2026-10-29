# QA: PR #151 – CI4 Control Panel settings port

- **Head:** `e63b65ddf2d657caab05abc75145b61e19ac8dee` (merge of `feature/ci4` `24830e7`, incl. #150, onto reviewed `62622fc`; CI run 40792698486, 25/25)
- **Date:** 2026-10-29 (EET)
- **Scope:** `/settings` index; General, Appearance/Branding (logo), Locale, List, Items, Limits, Labels, Uploads, AI autofill, Data (import/export), System; routes and compiled routes, permission, CSRF, secrets, tests.

## Verdict

**PASS (APPROVE).** No H or M findings. There is one L hardening note and two N notes. One item is deferred.

## Checklist

- [x] 0 behind `feature/ci4`. `check-commits` ok, `php -l` clean, deptrac 0 violations (new `ControlPanel` layer).
- [x] Merge delta (`e63b65d`):
  - `--remerge-diff` touches only `docs/HANDOFF.md` (items 7/8) and `ci4/app/Config/RoutesCompiled.php`.
  - Diff-of-diffs (`767126a..62622fc` vs `24830e7..e63b65d`, excluding HANDOFF, CHANGELOG, docs/qa): the only difference is the regenerated compiled routes.
  - `.gitleaks.toml` is unchanged from the base.
- [x] Compiled routes:
  - `spark apotheke:routes --check` is up to date, and a fresh regeneration is byte-identical.
  - 218 handlers, all resolving to an existing class and method. No reference to the removed Autofill settings controller remains.
  - All 54 Control Panel routes (13 GET, 13 HEAD, 28 POST) carry `permission => settings` (admin-only, not grantable to guests). The 11 sign-in/users routes keep `users`.
  - CSRF: the global session CSRF filter covers every POST except login, register and logout.
  - A stale permission or a stale handler in the compiled file fails `--check` and `CompiledRoutesTest`. At run time, a file that doesn't match `Routes.php` is not used.
  - `CompiledRoutesTest` + `PermissionFilterTest`: 199 OK.
- [x] Parity:
  - `Ci4ControlPanelParityTest` compares both apps on every page; save, invalid, array and reset cases; logo cases; labels; item defaults; import/export; token and Test buttons; unknown paths and methods; and viewer, guest and signed-out callers with and without CSRF. Status, headers, body and DB state match.
  - Parity gate (3 rounds; budgets not judged on a shared box): 223 steps, **0 differences, 0 pending** on SQLite and PG.
- [x] Validation and messages match legacy:
  - token rule (1–512 printable, no spaces) in the setter and in the section save;
  - SVG cleaner, size and pixel limits;
  - import ignores unknown and protected keys, and sign-in keys unless the caller is admin.
- [x] Storage is DB-compatible: same `settings` keys and values. The token is stored `enc:v1:` with a usable `APP_KEY`.
- [x] `Ci4PageHttpTest`: a refused token save (unusable `APP_KEY`) now expects **422 `text/html`** with the message and the token form. This matches the legacy handler's 422 HTML section page. The row-preserved, log-notice and no-echo assertions are kept.
- [x] Rate limits: none on the Control Panel in legacy, and none added (parity). See N-741.
- [x] Suites at `e63b65d` (sequential, light load):
  - root filtered (Control Panel parity, page HTTP, auth, HTTP, settings, autofill, gate): SQLite 127 OK; PG 127 with 1 environment-only error (the local role cannot create roles; CI is green);
  - module suite on PG: 753 OK.
- [x] Mutations (at `62622fc`, code unchanged since): 6/6 killed.
  - permission `settings` changed to `view`;
  - token printed into its form;
  - token rule removed from the setter;
  - token rule removed from the section save;
  - admin rule for sign-in keys removed from import;
  - typed token dropped from the section save.

## Security

- **Token is write-only.** Secret fields are excluded from section forms, so no save, re-render or Test re-render echoes the token. `all()`, `get()` and `export()` never return it. The token card shows only whether it is set (password input with no value). Moving the service address without a new token clears it, as legacy.
- **Logs.** Tests assert that the token and the key never appear in the body or the log, on both the refused and the stored path.
- **System page.** The DB password is shown only as set or empty. DSN output is limited to host, port and dbname. `APP_KEY` and the token are not listed.
- **Logo upload.** A real upload is required and size is capped before parsing. DOCTYPE and entities are refused, and network access is off. Elements and attributes go through an allowlist, and scriptable and external references are dropped. Raster logos are re-encoded. Same as legacy.
- **Test buttons.** The address is validated, the timeout is clamped to 3–60 s, and a typed token is used only for that request.
- **Placeholder literal:** the parity test's placeholder matches the exact-value #153 allowlist entry. **Deferred** to the L-700 follow-up (run-time value); not a finding.

## Findings

### L-740: settings import reads the upload before the size cap
The import action reads the whole uploaded file and only then applies `IMPORT_MAX_BYTES`. It is bounded by PHP upload limits and is admin-only. Suggest checking the file size first, or reading at most the cap plus one byte. Legacy has no cap, so this is hardening, not parity.

### N-741: Test buttons are not throttled (parity)
Admin-only outbound Test requests have no rate limit, as legacy. The clamped timeout bounds each request. Consider a small per-session limit when rate limiting is generalised.

### N-742: Cloudflare-phase notes (not blockers here)
- Logo install writes files to the local data directory and needs GD and DOM.
- Uploads use temporary files.
- `SystemInfo` reads the process environment and checks the config file on disk.
- An autofill save makes a synchronous outbound info request (≤5 s).

These need adapters (object storage, image service, a bindings view) in that phase.

## Notes

The CI visual list adds `control-panel.spec.ts` and `autofill-settings.spec.ts`. Two `CI4_UNPORTED` entries are removed. `ParityGateTest` requires ≥223 steps and no pending steps. Visual/E2E tests were not run locally; CI covers them.
