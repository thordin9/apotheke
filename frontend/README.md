# Apotheke front end: styles and static assets

This folder holds the design system the Apotheke web app (Svelte 5, step S1) and the
landing site share, plus the single Vite build that produces both. Tokens stay in
`src/styles/tokens.css`. The build does not copy that file.

```
frontend/
  package.json               Vite (the only direct dependency)
  vite.config.js             multi-page build, shared chrome, release stamp, SPA base
  index.html                 landing page, served at /
  privacy/index.html         /privacy/
  404.html                   static not-found page (root-absolute URLs)
  app/index.html             app shell at /app/; S1 replaces the router
  src/partials/              header and footer, injected into every page at build time
  src/app.js                 History API routes for the app shell
  src/styles/tokens.css      design tokens: the single source
  src/styles/base.css        reset and accessibility floor
  src/styles/landing.css     landing, privacy, 404 and the app shell
  src/consent.js             cookie notice; the app shell imports the same functions
  src/styles/components.css  app components; imports tokens.css and base.css
  public/fonts/              self-hosted WOFF2 files and their OFL licence texts
  public/assets/icons.svg    icon sprite (<svg class="icon"><use href="/assets/icons.svg#search"/></svg>)
  public/assets/brand/       logo-light.svg, logo-dark.svg, mark.svg, mark-small.svg, app-icon.svg,
                             favicon.svg, favicon-32.png, apple-touch-icon-180.png, og-1200x630.png
  public/assets/mascot/      pose-*.svg, the box mascot's poses
  public/assets/shots/       landing screenshots
  public/_headers            cache and CSP for the static site
  public/robots.txt
  public/sitemap.xml         / and /privacy/
  public/site.webmanifest
  public/favicon.svg
  public/apple-touch-icon.png
  scripts/contrast.mjs       WCAG contrast check for tokens.css (plain Node 18+, no packages)
  scripts/release-stamp.mjs  {version} and {date} from VERSION and the git tag
  scripts/spa-base.mjs       SPA mount path, default /app/
```

## Build

Node 22. From `frontend/`:

```
npm ci
npm run build
```

Output is `frontend/dist/` (gitignored). `dist/index.html` is the landing page,
`dist/privacy/index.html` is `/privacy/`, `dist/404.html` is the not-found page, and
`dist/app/index.html` is the app shell. Hashed CSS and JS are under `dist/assets/`.
The CSS is left unminified: Vite 8 minifies with lightningcss, which treats `U+0000`
inside `unicode-range` as a string terminator and would drop the latin and polytonic
Greek faces. Fonts stay at `/fonts/` because `tokens.css` uses those root-absolute
urls. Brand, mascot and screenshot files are copied unhashed. There is no
`config.json`; each environment supplies its own.

`{version}` and `{date}` are filled from the repo-root `VERSION` file and the
creatordate of the git tag `v<version>`. If that tag is not in the clone, the date
is the build date (UTC, `YYYY-MM-DD`).

`APOTHEKE_SPA_BASE` (default `/app/`) is the mount path of the app on this origin.
The shell's canonical URL and `data-spa-base` use it, and the router is given the same path when the scripts are built. The P7 comment in the landing
page names `/app/`, the same default. The Worker answers bare `/app` with a 301 to
`/app/`, and serves `/app/index.html` with a 200 for `/app/` and for any `/app/*`
path that does not end in a file extension (`/\.[A-Za-z0-9]+$/`). A missing file
that does end in an extension stays a 404. `vite preview` and the end-to-end
server do the same, so a refresh of `/app/sign-in` stays on the shell.

## One product

`src/partials/header.html` and `src/partials/footer.html` are the only header and
footer. A Vite `transformIndexHtml` plugin injects them while building, and fills
the nav. No runtime script is involved. The landing page, privacy page and 404
offer **Open the app** (`/app/`) and **Sign in** (`/app/sign-in`). Inside the app
the same bar offers **Home** (`/`). **Privacy** (`/privacy/`) stays in the footer
on every page, including `© 2026 Stathis Papandreou`.

`src/app.js` is a plain module with a History API router: `/app/` (what Apotheke
is, and a link to sign in), `/app/sign-in`, and a not-found view for any other
`/app/*` path. That view sets `noindex` and links back to the app and the home
page. Links inside the app do not reload the page. The router does not use web
storage.

Sign-in stays disabled until cookies are accepted, and asks for nothing until
then. After Accept it requests `GET /api/v1/session` on this origin. A missing
or unusable token shows "Sign-in is coming soon." with a link home, and the form
stays disabled. The consent cookie is unchanged: nothing is stored before Accept,
Decline stores nothing, and `Path=/` shares `apotheke_consent=v1` across `/` and
`/app/`.

`/privacy/` names the operator shown in the footer. The imprint page
is not part of this site.

## The token contract

`tokens.css` has three layers. Components read only layer 2.

1. **Brand** (`--brand-*`). Set once for light in `:root`, and again for dark in two identical
   blocks: `:root[data-theme="dark"]` and the `prefers-color-scheme: dark` media block.
   These are the only values a rebrand changes.

   | Token | Role |
   |---|---|
   | `--brand-primary`, `-strong`, `-ink` | the accent: primary buttons, links, focus ring, progress; hover; text on it |
   | `--brand-primary-soft`, `-soft-ink` | quiet accent fill ("In" pill, selection) and its text |
   | `--brand-secondary`, `-shade`, `-ink` | the brand orange: fills and illustration only; its edge; text on it |
   | `--brand-secondary-text` | orange when it has to be text on paper |
   | `--brand-secondary-soft`, `-soft-ink` | highlight fill ("Low stock") and its text |
   | `--brand-tertiary`, `-ink`, `-soft` | danger and alerts; text on it; error notice fill |
   | `--brand-outline` | outline ink for strong rules and marks |
   | `--brand-tan`, `--brand-grey` | illustration only |
   | `--brand-wordmark` | the name beside the mark |

2. **Semantic** (`--color-*`). Derived from layer 1 with `var()`, never edited for a rebrand:
   `--color-accent`, `--color-accent-strong`, `--color-accent-ink`, `--color-accent-soft(-ink)`,
   `--color-focus`, `--color-highlight(-ink)`, `--color-brand-fill(-ink)`, `--color-brand-text`,
   `--color-error`, `--color-error-ink`, `--color-error-soft`, `--color-wordmark`, plus the
   neutrals (`--color-paper`, `-paper-2`, `-paper-3`, `-ink`, `-ink-2`, `-muted`, `-rule`,
   `-rule-2`, `-field-edge`), `--color-success`, `--color-overlay` and `--shadow-card`.

3. **Components** (`components.css`) use only `--color-*`, the space, type and motion scales,
   and the mascot sizes `--mascot-s` (48 px), `--mascot-m` (120 px) and `--mascot-l` (200 px).

Mode: the page follows `prefers-color-scheme`; `<html data-theme="light|dark">` overrides it.
All durations become 0 under `prefers-reduced-motion: reduce`.

## Swapping the brand

1. Edit the `--brand-*` values in `:root` (light).
2. Edit the same tokens in **both** dark blocks, keeping them identical.
3. Run the check:

   ```
   node frontend/scripts/contrast.mjs            # human-readable
   node frontend/scripts/contrast.mjs --json     # for CI
   node frontend/scripts/contrast.mjs path/to/other/tokens.css
   ```

   It resolves the `var()` chains for light and dark, reads hex, `rgb()` and `oklch()`, checks
   every text pair at 4.5:1 and every non-text pair at 3:1 (WCAG 2.2 AA), and exits with 1 on a
   failure or if the two dark blocks differ.
4. Replace the files in `public/assets/brand/` if the mark changes. Keep the SVGs flat (no
   gradients, scripts or `style` attributes) so they load under `img-src 'self'`.

## Fonts

| Family | File | Range | Licence |
|---|---|---|---|
| Epilogue (variable) | `epilogue-latin-wght-normal.woff2`, `epilogue-latin-ext-wght-normal.woff2` | latin, latin-ext | `OFL-Epilogue.txt` |
| Apotheke Greek: Commissioner (variable) | `commissioner-greek-wght-normal.woff2` | U+0370-03FF | `OFL-Commissioner.txt` |
| Apotheke Greek: Noto Sans (variable) | `noto-sans-greek-ext-wght-normal.woff2` | U+1F00-1FFF (polytonic) | `OFL-Noto-Sans.txt` |
| Nunito 800 (wordmark only) | `nunito-latin-800-normal.woff2` | latin | `OFL-Nunito.txt` |
| IBM Plex Mono 400 | `ibm-plex-mono-latin-400-normal.woff2` | latin | `OFL-IBM-Plex-Mono.txt` |

The `@font-face` rules use absolute `/fonts/` URLs and `font-display: swap`; the Greek files
are only fetched when Greek text is on the page.

## Mascot

Use a pose as decoration next to text that says the same thing:

```html
<img class="brand-art mascot" src="/assets/mascot/pose-asleep.svg" alt="" width="120" height="120">
```

Always set `width` and `height` (no layout shift) and `alt=""` in the app. Classes: `.mascot`
(120 px), `.mascot-s` (48 px), `.mascot-l` (200 px).

### Halo in dark mode

The poses and marks are drawn in outline ink `#04303d`, which is close to dark paper (1.23:1).
Put `.brand-art` on every pose, mark and `logo-dark.svg` image. In dark mode it draws a 1 px
rim of `--brand-halo` round the artwork (`filter: var(--mascot-halo)`, four 0-blur
`drop-shadow()`s); in light mode `--mascot-halo` is `none`. Inside an `.on-dark` container
(the fixed `--logo-dark-ground`) the rim is always drawn, from `--logo-dark-halo`.
No SVG is duplicated and nothing is inline, so it works under `style-src 'self'`.
A brand swap that changes `--brand-halo` must keep it at 3:1 on all three papers;
`contrast.mjs` checks it.
