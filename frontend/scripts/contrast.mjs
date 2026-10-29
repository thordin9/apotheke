#!/usr/bin/env node
// Apotheke token contrast check. Dependency-free: plain Node 18+.
//   node frontend/scripts/contrast.mjs [path/to/tokens.css] [--json]
// Reads tokens.css, resolves var() chains for light and dark, converts oklch/hex/rgb to sRGB
// and checks every pair below against WCAG 2.2 (1.4.3 text 4.5:1, 1.4.11 non-text 3:1).
// Exit code 1 on any failure, or if the two dark blocks have drifted apart.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const args = process.argv.slice(2);
const json = args.includes('--json');
const file = args.find((a) => !a.startsWith('--')) ?? resolve(dirname(fileURLToPath(import.meta.url)), '../src/styles/tokens.css');

// [foreground, background, minimum ratio, what it is]; names are --color-*, brand:* (--brand-*) or a raw --token
export const PAIRS = [
  ['ink', 'paper', 4.5, 'body text'], ['ink', 'paper-2', 4.5, 'text on panels'], ['ink', 'paper-3', 4.5, 'text on wells'],
  ['ink-2', 'paper', 4.5, 'secondary text'], ['ink-2', 'paper-2', 4.5, 'secondary text on panels'],
  ['muted', 'paper', 4.5, 'hints'], ['muted', 'paper-2', 4.5, 'hints on panels'], ['muted', 'paper-3', 4.5, 'hints on wells'],
  ['paper', 'ink', 4.5, 'primary button, slab nav'],
  ['accent', 'paper', 4.5, 'accent text and links'], ['accent', 'paper-2', 4.5, 'accent text on panels'],
  ['accent-ink', 'accent', 4.5, 'text on accent fill'],
  ['accent-soft-ink', 'accent-soft', 4.5, '"In" pill, selection'], ['muted', 'accent-soft', 4.5, 'hint on accent-soft'],
  ['highlight-ink', 'highlight', 4.5, '"Low stock" pill'],
  ['error', 'paper', 4.5, 'error and danger text'], ['error', 'paper-2', 4.5, 'error text on panels'], ['error-ink', 'error', 4.5, 'danger button'], ['error', 'error-soft', 4.5, 'error notice'], ['success', 'paper', 4.5, 'success text'],
  ['field-edge', 'paper', 3, 'field border'], ['field-edge', 'paper-2', 3, 'field border on panels'],
  ['focus', 'paper', 3, 'focus ring'], ['focus', 'paper-2', 3, 'focus ring on panels'], ['focus', 'paper-3', 3, 'focus ring on wells'],
  ['accent', 'paper-3', 3, 'progress and wake bar on track'],
  ['brand:primary-ink', 'brand:primary', 4.5, 'text on brand primary'],
  ['brand:secondary-ink', 'brand:secondary', 4.5, 'text on brand secondary (orange)'],
  ['brand:secondary-soft-ink', 'brand:secondary-soft', 4.5, 'text on secondary soft'],
  ['brand-text', 'paper', 4.5, 'darker orange as text'], ['brand-text', 'paper-2', 4.5, 'darker orange text on panels'],
  ['brand-fill-ink', 'brand-fill', 4.5, 'text on an orange fill (badge, highlight bar)'], ['brand:secondary-shade', 'paper', 3, 'shade border that edges an orange fill (fill alone is decorative)'],
  ['accent-strong', 'paper', 4.5, 'hovered link'], ['accent-ink', 'accent-strong', 4.5, 'text on hovered button'],
  ['brand:tertiary', 'paper', 4.5, 'brand tertiary (red) as text'],
  ['brand:tertiary-ink', 'brand:tertiary', 4.5, 'text on brand tertiary'],
  ['brand:outline', 'paper', 3, 'outline ink on paper (non-text)'], ['ink', 'highlight', 4.5, 'body text on a highlighted row'], ['wordmark', 'paper', 4.5, 'wordmark text'],
  // 1.4.11 non-text: the halo is the rim that keeps the outline ink of poses and marks visible on dark paper (light mode draws none)
  ['brand:halo', 'paper', 3, 'mascot and mark halo on paper'], ['brand:halo', 'paper-2', 3, 'mascot and mark halo on panels'], ['brand:halo', 'paper-3', 3, 'mascot and mark halo on wells'],
  ['--logo-dark-halo', '--logo-dark-ground', 3, 'halo on the fixed dark logo ground'],
];

const css = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
function block(re) { const m = css.match(re); if (!m) return null; let i = m.index + m[0].length, d = 1; const s = i; while (d && i < css.length) { if (css[i] === '{') d++; else if (css[i] === '}') d--; i++; } return css.slice(s, i - 1); }
function decls(body) { const o = {}; for (const m of (body ?? '').matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) o[m[1]] = m[2].trim(); return o; }
const light = decls(block(/(^|\})\s*:root\s*\{/));
const darkA = decls(block(/:root\[data-theme="dark"\]\s*\{/));
const darkB = decls(block(/:root:not\(\[data-theme="light"\]\):not\(\[data-theme="dark"\]\)\s*\{/));
const drift = [...new Set([...Object.keys(darkA), ...Object.keys(darkB)])].filter((k) => darkA[k] !== darkB[k]);

function resolveVar(v, env, depth = 0) {
  if (depth > 20) throw new Error('var() loop at ' + v);
  return v.replace(/var\(\s*(--[\w-]+)\s*(?:,\s*([^)]+))?\)/g, (_, n, fb) => (env[n] !== undefined ? resolveVar(env[n], env, depth + 1) : fb ?? (() => { throw new Error('undefined ' + n); })()));
}
function toLinear(c) { return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }
function parse(c) {
  c = c.trim();
  let m = c.match(/^oklch\(\s*([\d.]+)(%?)\s+([\d.]+)\s+([\d.]+)(?:deg)?\s*(?:\/\s*([\d.]+%?))?\s*\)$/);
  if (m) {
    const L = m[2] ? +m[1] / 100 : +m[1], C = +m[3], h = (+m[4] * Math.PI) / 180;
    const a = C * Math.cos(h), b = C * Math.sin(h);
    const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3, mm = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3, s = (L - 0.0894841775 * a - 1.291485548 * b) ** 3;
    const lin = [4.0767416621 * l - 3.3077115913 * mm + 0.2309699292 * s, -1.2684380046 * l + 2.6097574011 * mm - 0.3413193965 * s, -0.0041960863 * l - 0.7034186147 * mm + 1.707614701 * s];
    const out = lin.some((x) => x < -0.002 || x > 1.002);
    return { lin: lin.map((x) => Math.min(1, Math.max(0, x))), alpha: m[5] ? parseFloat(m[5]) / (m[5].endsWith('%') ? 100 : 1) : 1, out };
  }
  m = c.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
  if (m) { const h = m[1].length === 3 ? [...m[1]].map((x) => x + x).join('') : m[1]; return { lin: [0, 2, 4].map((i) => toLinear(parseInt(h.slice(i, i + 2), 16) / 255)), alpha: 1, out: false }; }
  m = c.match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*(?:[,/]\s*([\d.]+))?\)$/);
  if (m) return { lin: [m[1], m[2], m[3]].map((x) => toLinear(+x / 255)), alpha: m[4] ? +m[4] : 1, out: false };
  throw new Error('cannot parse colour ' + c);
}
const Y = ({ lin: [r, g, b] }) => 0.2126 * r + 0.7152 * g + 0.0722 * b;
const ratio = (f, b) => { const [hi, lo] = [Y(f), Y(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
const name = (t) => (t.startsWith('--') ? t : t.startsWith('brand:') ? '--brand-' + t.slice(6) : '--color-' + t);

const results = []; let fails = 0;
for (const [mode, env] of [['light', light], ['dark', { ...light, ...darkA }]]) {
  for (const [fg, bg, min, what] of PAIRS) {
    const F = parse(resolveVar(`var(${name(fg)})`, env)), B = parse(resolveVar(`var(${name(bg)})`, env));
    const r = ratio(F, B); const ok = r >= min; if (!ok) fails++;
    results.push({ mode, fg: name(fg), bg: name(bg), ratio: +r.toFixed(2), min, ok, what, gamut: F.out || B.out ? 'clipped' : '' });
  }
}
if (json) console.log(JSON.stringify({ file, fails, drift, results }, null, 1));
else {
  console.log(`contrast: ${file}`);
  for (const x of results) console.log(`${x.ok ? 'pass' : 'FAIL'}  ${x.mode.padEnd(5)} ${x.fg.padEnd(28)} on ${x.bg.padEnd(24)} ${x.ratio.toFixed(2).padStart(5)} (>= ${x.min})  ${x.what}${x.gamut ? '  [out of sRGB, clipped]' : ''}`);
  if (drift.length) console.log(`FAIL  the two dark blocks differ: ${drift.join(', ')}`);
  console.log(`${results.length - fails}/${results.length} pairs pass${drift.length ? ', dark blocks drifted' : ''}.`);
}
process.exit(fails || drift.length ? 1 : 0);
