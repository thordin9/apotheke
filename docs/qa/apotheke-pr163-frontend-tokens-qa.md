# QA: PR #163, front end step T (design tokens, base styles, brand assets)

- **Head:** `9264cd19387409b799b73440cbf18c125c73809b`. 8 commits (7 frontend commits + a merge of main), base main, 0 behind, mergeable, same repo.
- **Diff vs main:** 44 files, all additions under `frontend/` (+1152/−0). The `frontend/` tree is byte-identical to the previously gated `9c2a47e`. The merge brought in only main's changes (#164 and related).
- **CI:** run 41026217825 on this exact head. All jobs green except Conventional Commits (waived below). Tests (sqlite and pgsql) now pass.
- **Verdict: PASS.** No open H/M findings.

## Waiver

The Conventional Commits job fails because the subjects start with `frontend:`. The PM waives this for #163 only and will squash-merge with subject `chore(frontend): design tokens, base styles and brand assets`. This is not a finding.

## Checklist

- [x] **Scope.** Only additions, only under `frontend/`. Nothing in app/, modules/, public/, bin/, Dockerfile, .dockerignore, compose, composer.json/lock, workflows or `.gitleaks.toml`. Nothing in the app, the build or CI references `frontend/`.
- [x] **No remote loads.**
  - The only `http(s)://` strings are SVG `xmlns` namespaces and the URLs inside the OFL licence texts.
  - There are no CDNs, no protocol-relative hosts and no source maps.
  - `@import` and `url()` load only `./tokens.css`, `./base.css` and `/fonts/*.woff2`.
  - `contrast.mjs` imports only `node:` built-ins.
- [x] **CSP.** The app's CSP (`Core/Http/SecurityPolicy::CSP`) is `default-src 'self'` with `style-src 'self'` and `img-src 'self' blob: data:`. Fonts fall back to `'self'`.
  - Every asset is a same-origin file: no `data:` fonts, no inline styles or scripts.
  - The SVG sprite is used with `<use href="/assets/icons.svg#…">`.
  - No new origin and no `unsafe-*` source is needed.
- [x] **SVG safety.** None of the 23 SVGs contains `<script>`, `on*=` handlers, `href`/`xlink`, `<image>`, `foreignObject`, `<style>`, `style=`, `data:`, DOCTYPE or ENTITY, or metadata. The 2 PNGs are valid (32×32 and 180×180) with no text or EXIF chunks.
- [x] **Sizes.** `frontend/` totals 741 KB. The largest files are SVGs up to 55 KB and WOFF2 fonts up to 36 KB (subset or variable).
- [x] **Font licences.** Epilogue, Commissioner, Noto Sans, Nunito and IBM Plex Mono are all SIL OFL 1.1, with no Reserved Font Name in the shipped texts.
  - Each WOFF2 file's name table matches its OFL text (copyright, licence URL, gftools builds).
  - The OFL texts are shipped next to the fonts, as the OFL requires.
  - Bundling OFL fonts is compatible with the repo's MIT licence.
- [x] **Brand assets.** The marks, icons and mascot poses are the project's own. They were made from source art the owner approved, by generating new poses and tracing them with vtracer (per the Front-End Specialist's proposal). The repo itself records no provenance or licence statement for them (L-1081, deferred).
- [x] **Contrast.** `node frontend/scripts/contrast.mjs` on Node 20 reports 88/88 pairs pass (exit 0).
  - Mutation: setting `--brand-primary` to `#b9a6d8` gives 80/88 and exit 1.
  - Mutation: a drift between the two dark blocks gives exit 1 with "the two dark blocks differ".
- [x] **Docker and build.** The Dockerfile COPYs only app, modules, public, migrations, bin, spark, the docker/ files and vendor, so `frontend/` is not in the image. It is only in the build context (N-1082, deferred).
- [x] **Secrets.** gitleaks 8.30.1 on the frontend range and on the merge-in range: no leaks. `.gitleaks.toml` is unchanged. No paths, hostnames or e-mail addresses appear in `frontend/`.
- [x] **Re-gate after main merge.** `frontend/` is byte-identical to `9c2a47e`. The only non-frontend changes vs that head are main's (release squash-links, VERSION mask, CHANGELOG). gitleaks on the non-merge commits of the merge range: no leaks.

## Findings

**M-1080 (main): the page golden pinned the app version — fixed on main by #164.** `Ci4PagesGoldenTest` now masks VERSION. Tests pass on this head.

**L-1081: brand-asset provenance is not recorded.** Deferred to the Front-End Specialist's next PR: add a short `frontend/public/assets/NOTICE` (or README section) with the owner, the source art and the licence or trademark position.

**N-1082:** `frontend/` is not in `.dockerignore`. Deferred to the Front-End Specialist's next PR. It adds about 0.7 MB to the build context, but nothing to the image.

**N-1083: squash subject type — accepted by the PM.** The squash subject is `chore(frontend): design tokens, base styles and brand assets`, so the release workflow cuts no version for files the app does not use yet.

**N-1084:** the `@font-face` rules use absolute `/fonts/` URLs. Deferred to the font-wiring PR: when the fonts are wired in, they must be served from the docroot under that path, with the long-cache headers that `/assets/` gets in nginx.

## Security

- No remote origins and no inline code, and nothing that needs a CSP change.
- The SVGs are inert, the PNGs carry no metadata, and gitleaks is clean.
- The added files are not in the image.
