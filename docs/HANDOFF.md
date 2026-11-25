# Hand-off: the CodeIgniter 4 app

A one-screen status of the CodeIgniter 4 port. Every pull request into `main` updates this page; keep it to about one
screen and move detail to the [hand-off log](history/handoff-log.md). Plan and module rules:
[PLAN.md](plans/feature12-ci4/PLAN.md), [ADR 001](adr/001-codeigniter4.md), [ADR-002](adr/002-edge-storage.md),
[AGENTS.md](../AGENTS.md).

## Current state

- `main` is the CI4 app (v3.0.0 and later; latest release v3.68.0). The 2.6.x line has security support from `old`
  for 90 days after 3.0.0. Only `main`, `old` and open PR heads remain.
- Every module is ported, with legacy parity at step 16; detailed module notes stay in the
  [log](history/handoff-log.md#current-state).
- P4 edge storage ([ADR-002](adr/002-edge-storage.md)) is in use on the hosted API (flip 2026-11-12). Backup restore
  on d1 is in: each restore keeps the catalog before it under the installation's `backups/`. On d1, duplicating an
  item with its photos, and adding a new location or category in the same step as saving the item, stay refused. Do
  not move real data onto the hosted installation until a backup has been tested by a round trip there.
- The lifecycle Workflow (`apotheke-lifecycle`) runs on `apotheke-api`. The operator console Worker binds it and
  starts a confirmed job; it does not host the Workflow.
- The operator console is live at `console.getapotheke.com`, behind Cloudflare Access. Merged notes:
  [log, Pull requests](history/handoff-log.md#pull-requests).
- Operator console writes: both Worker configs declare `OPS_WRITES_ENABLED=1`; the approved deploy (apotheke-api
  first, then apotheke-ops) turns on the implemented write routes and lifecycle confirmations. Access, CSRF and the
  primary refusals stay. `OPS_PROVISIONING=0` and `LIMITS_ENABLED=0` are unchanged; the ops deploy config accepts
  writes `0` or `1` for rollback and still refuses provisioning or limits. See
  [deploying the console](operator-console.md#deploying-the-console).

## Open pull requests

- Enable operator console writes in production, and drop the unused `AUTOFILL` binding from `apotheke-ops`
  (branch `cursor/lifecycle-followups`, [#362](https://github.com/thordin9/apotheke/pull/362)).
- Repo documentation sweep (branch `docs/repo-sweep`): docs-only updates off v3.68.0. No pull request yet.

## Next steps

1. Land the open pull requests above.
2. Self-host storage bind-and-create steps on [Self-hosting on Cloudflare](self-host-cloudflare.md#storage) are still
   to be written. Planned operator-console endpoints that are not served yet stay in
   [Operator console](operator-console.md#planned).
3. Older backlog: [log, Next steps](history/handoff-log.md#next-steps).

## Lanes

- Coder: CI, Docker, CLI port, syncs, docs; Coder2: auth/accounts, backup/restore, import/export, P4 restore and
  unit-of-work sites; DevOps: Cloudflare bindings and deploy. History: [log, Lanes](history/handoff-log.md#lanes).
- Shared files (`Config/Routes.php`, `CHANGELOG.md`, `deptrac.yaml`, `Config\Services`, AGENTS module graph):
  append your own block in a small hunk; do not reorder other blocks. Merge main (no rebase) before a moved push.

## Links

- [Hand-off log](history/handoff-log.md): every PR, module notes, lanes, next steps and
  [tracked differences](history/handoff-log.md#tracked).
- [Database](database.md), [Testing](testing.md), [Releasing](releasing.md), [API](api.md),
  [Deployment and operations](deployment-and-operations.md), [Operator console](operator-console.md),
  [Workers migration plan](future/workers-migration-plan.md) (a future option, not scheduled).
- CI4 Playwright: the `CI4_UNPORTED` skip list (`tests/visual/ci4-unported.json`, read by
  `tests/visual/fixtures.ts`) holds one key per test whose page v4 does not serve, harvested from runs
  against v4 - the harness's default since G1's flip; `E2E_APP=php` boots v3 - by
  `v4/scripts/visual-v4-map.mjs` (plan §5 G1). Every key must name its page
  somewhere in this file — `v4: npm run skip-list:check` fails a key whose page is not named there, and
  fails this bullet if it still claims the list has no keys once one exists, so a skip cannot leave
  this sentence behind. The skipped tests, with their count, are listed under
  [CI4 Playwright skips](#ci4-playwright-skips), a section generated from the map.

## CI4 Playwright skips

Under the default harness (v4; `E2E_APP=php` boots v3) these 112 tests skip: each names a page the v4 app answers 501 for, or
needs a fixture that runs PHP (`sql`, `settings`). This section is generated from
`tests/visual/ci4-unported.json` by `node scripts/check-visual-skip-list.mjs --write-handoff`;
`skip-list:check` fails while the two disagree, so a hand edit does not survive a check (plan D80).
A key is evaluated inside the test's fixtures: a spec that shells `php` itself skips in the spec,
and a test whose v4 boot died is a defect, never a key.

- `add.spec.ts`
  - add item › library input, inline new location + category, "Save & new" keeps them
  - add item › photo from the camera input, defaults, stepper, tags and custom fields @smoke
  - add item › validation errors are shown inline
- `attachments.spec.ts`
  - notes and attachments › Markdown notes render safely @smoke
  - notes and attachments › attach a PDF, download it, delete it
  - notes and attachments › wrong file type shows an error
- `auth.spec.ts`
  - sign-in (APP_PASSWORD = account admin) › login, wrong password, logout @smoke
- `barcode.spec.ts`
  - barcodes › barcode field on the edit form
  - barcodes › manual lookup: unknown code → prefilled new item, then → the item @smoke
- `branding.spec.ts`
  - branding › header logo, favicon links and manifest @smoke
- `bulk.spec.ts`
  - bulk actions › select mode: pick cards, move, tag, select all, delete with confirm
- `consent.spec.ts`
  - cookie consent › no cookie before Accept, then the app as before @smoke
- `control-panel.spec.ts`
  - control panel › appearance: SVG logo is cleaned
  - control panel › appearance: accent preset, live preview, contrast warning
  - control panel › appearance: colour picker and typed value stay in sync
  - control panel › appearance: upload a PNG logo, then reset
  - control panel › data: backup, CSV and settings export / import
  - control panel › general: invalid values show an error and keep the input
  - control panel › general: name and tagline
  - control panel › items: defaults pre-fill the add form
  - control panel › limits: expiry window and loan period
  - control panel › list: page size, sort and compact view
  - control panel › locale: currency, number and date formats
  - control panel › reached from the menu, every section opens @smoke
  - control panel › security: turn sign-in on, sign in, turn it off
  - control panel › system: bootstrap values are read-only
  - control panel › uploads: values are validated
- `data.spec.ts`
  - labels, export, backup › CSV export downloads the full catalog @smoke
  - labels, export, backup › QR labels: all locations, one location, per item, item label, print
  - labels, export, backup › backup download, restore dry run, confirm @smoke
  - labels, export, backup › restore rejects files that are not backups
- `duplicate.spec.ts`
  - duplicate item › duplicate with photos opens the edit form of the copy
- `errors.spec.ts`
  - friendly error pages › 404 for unknown pages and items
  - friendly error pages › expired session (CSRF) shows a friendly page and changes nothing
- `expiry.spec.ts`
  - expiry / warranty › set a date, badges, tiles, filters, history @smoke
  - expiry / warranty › without JavaScript the filter links still work
- `favourites.spec.ts`
  - favourites and recently viewed › recently viewed strip on the list
  - favourites and recently viewed › star on a card, favourites chip and filter @smoke
  - favourites and recently viewed › star works without JavaScript
- `history.spec.ts`
  - item history and recent activity @smoke
- `import.spec.ts`
  - CSV import › round-trips the CSV export
  - CSV import › upload, map, preview with errors, import @smoke
- `item.spec.ts`
  - item actions › one Edit button; Delete kept apart on phones
  - item page › delete asks for confirmation @smoke
  - item page › detail page: photo gallery with arrows, counter and thumbnails @smoke
  - item page › edit: change fields, replace and remove the cover photo
  - item page › photos: add several, reorder, set cover, delete
  - item page › quantity +/- on the item page
- `labels.spec.ts`
  - bulk QR labels › a location with its sub-locations
  - bulk QR labels › print labels for a selection; sizes from the Control Panel @smoke
- `lending.spec.ts`
  - lending › lend, badges, filters, Lent page, return @smoke
- `list.spec.ts`
  - empty catalog › shows the empty state with a call to action
  - empty catalog › the labels page has an empty state
  - list, overview and search › filter sheet: location, category, tag, sort, chips and clear all
  - list, overview and search › grid of cards with photos, chips and quantities @smoke
  - list, overview and search › instant search, including Greek without accents @smoke
  - list, overview and search › overview: tiles, range toggle, collapse persistence, tap to filter
  - list, overview and search › quantity +/- on a card
- `locations-nested.spec.ts`
  - nested locations › build a tree, filter by a parent, trail on the item page, move and delete @smoke
  - nested locations › search finds items in sub-locations; overview rolls counts up
- `manage.spec.ts`
  - delete a location and leave its items unassigned
  - manage categories › add, rename, default, delete-with-move
  - manage locations › add, rename, default, delete-with-move @smoke
- `merge.spec.ts`
  - merge duplicates › cancel leaves both items
  - merge duplicates › pick a target, confirm, everything moves over @smoke
- `photos.spec.ts`
  - photo editing › drag to reorder; first photo becomes the cover
  - photo editing › rotate + drag a crop on the canvas, save a new version @smoke
  - photo editing › works without JavaScript: manual fields
- `qa-fixes.spec.ts`
  - QA fixes › /new has one field per name; the no-JS "new location" field is saved
  - QA fixes › 375px: tile labels and the search placeholder are not cut off
  - QA fixes › the camera button never covers the end of the page
- `qa-v2.spec.ts`
  - QA v2.0.0 follow-ups › new item: ★ explained, two columns on desktop, Save bar never covers a field
  - QA v2.0.0 follow-ups › overview: collapsed on phones, open on wider screens
  - QA v2.0.0 follow-ups › stocktake of an empty location offers to add items there
- `roles.spec.ts`
  - roles & sign-in options › accounts off: everyone is an anonymous admin
  - roles & sign-in options › guest browsing: off by default, then look-only guests
  - roles & sign-in options › roles editor: custom role with granular permissions @smoke
  - roles & sign-in options › self-registration with admin approval
- `saved-filters.spec.ts`
  - saved filters › save, apply, rename and delete a filter combination @smoke
- `search.spec.ts`
  - full-text search › ranked, highlighted, snippets and typo tolerance @smoke
  - full-text search › shows the engine in System
  - full-text search › works without JavaScript
- `stock.spec.ts`
  - low-stock alerts › set a level, badge on card + detail, +/- toggles it, tile and filter
- `stocktake.spec.ts`
  - stocktake › tick, scan a misplaced item, finish with moves @smoke
  - stocktake › works without JavaScript
- `theme.spec.ts`
  - theme switch › Auto / Light / Dark is applied instantly and persists @smoke
  - theme switch › works without JavaScript (form POST)
- `undo.spec.ts`
  - undo › bulk move, then undo; dismiss hides the bar
  - undo › delete an item, then undo from the bar @smoke
  - undo › edit, then undo
- `users.spec.ts`
  - users & roles › first admin, add a viewer, the viewer can only look @smoke
- `value.spec.ts`
  - purchase price and value totals › overview totals, sort by value, editing a price @smoke
- `visual-batch5.spec.ts`
  - visual: batch 5 features › CSV import preview
  - visual: batch 5 features › photo editor
  - visual: batch 5 features › roles editor and registration
  - visual: batch 5 features › search with highlights
  - visual: batch 5 features › stocktake start and scan
- `visual-features.spec.ts`
  - visual: batch 4 features › favourites and recently viewed
  - visual: batch 4 features › labels for a selection
  - visual: batch 4 features › merge confirm
  - visual: batch 4 features › notes (Markdown) and attachments
  - visual: batch 4 features › undo bar
- `visual-settings.spec.ts`
  - visual: control panel › custom accent (terracotta)
  - visual: control panel › custom logo in the header
  - visual: control panel › home and sections
- `visual.spec.ts`
  - visual › expiry, value, saved filters, barcode
  - visual › forms
  - visual › item detail and photo manager
  - visual › list with overview
  - visual › manage, labels, backup, activity
  - visual › stock, bulk, lending, nested locations
  - visual: empty › empty catalog
