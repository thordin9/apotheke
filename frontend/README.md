# Apotheke front end: styles and static assets

This folder holds the design system the Apotheke web app (Svelte 5 + Vite, coming in P6)
and the landing site share. Everything here is a static file: there is no build step and
no package to install.

```
frontend/
  src/styles/tokens.css      design tokens: the single source (the landing site keeps a byte copy that its CI compares)
  src/styles/base.css        reset and accessibility floor
  src/styles/components.css  app components; imports tokens.css and base.css
  public/fonts/              self-hosted WOFF2 files and their OFL licence texts
  public/assets/icons.svg    icon sprite (<svg class="icon"><use href="/assets/icons.svg#search"/></svg>)
  public/assets/brand/       logo-light.svg, logo-dark.svg, mark.svg, mark-small.svg, app-icon.svg,
                             favicon.svg, favicon-32.png, apple-touch-icon-180.png
  public/assets/mascot/      pose-*.svg, the box mascot's poses
  scripts/contrast.mjs       WCAG contrast check for tokens.css (plain Node 18+, no packages)
```

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
<img class="mascot" src="/assets/mascot/pose-asleep.svg" alt="" width="120" height="120">
```

Always set `width` and `height` (no layout shift) and `alt=""` in the app. Classes: `.mascot`
(120 px), `.mascot-s` (48 px), `.mascot-l` (200 px).
