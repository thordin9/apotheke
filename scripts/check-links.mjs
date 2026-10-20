#!/usr/bin/env node
// Check relative links (files and #anchors) in Markdown: README.md, CONTRIBUTING.md,
// AGENTS.md, and every .md file under docs/ (nested, including qa and superpowers).
// External http(s)/mailto links are not fetched. Usage: node scripts/check-links.mjs [--list]
import fs from 'node:fs';
import path from 'node:path';

const root = process.env.APOTHEKE_LINK_ROOT
  ? path.resolve(process.env.APOTHEKE_LINK_ROOT)
  : path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');

function markdownUnder(relDir) {
  const abs = path.join(root, relDir);
  if (!fs.existsSync(abs)) return [];
  const out = [];
  const walk = (dir, rel) => {
    for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
      if (ent.name.startsWith('.')) continue;
      const childRel = rel + '/' + ent.name;
      if (ent.isDirectory()) walk(path.join(dir, ent.name), childRel);
      else if (ent.name.endsWith('.md')) out.push(childRel);
    }
  };
  walk(abs, relDir);
  return out.sort();
}

const files = [
  'README.md',
  'CONTRIBUTING.md',
  'AGENTS.md',
  ...markdownUnder('docs'),
];

// GitHub-style heading anchors: lowercase, drop punctuation (keep letters, digits, spaces, - and _), spaces → -.
const slug = s => s.trim().toLowerCase().replace(/<[^>]+>/g, '').replace(/[`*_~]/g, m => (m === '_' ? '_' : ''))
  .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/[^\p{L}\p{N} _-]/gu, '').replace(/ /g, '-');
const anchorsCache = new Map();
const anchors = file => {
  if (!anchorsCache.has(file)) {
    const seen = new Map(), out = new Set();
    let fence = false;
    for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
      if (/^\s*(```|~~~)/.test(line)) fence = !fence;
      const m = !fence && line.match(/^#{1,6}\s+(.*?)\s*#*\s*$/);
      if (!m) continue;
      const base = slug(m[1]), n = seen.get(base) || 0;
      seen.set(base, n + 1);
      out.add(n ? `${base}-${n}` : base);
    }
    anchorsCache.set(file, out);
  }
  return anchorsCache.get(file);
};

let bad = 0, checked = 0;
for (const rel of files) {
  const file = path.join(root, rel);
  let fence = false;
  fs.readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    if (/^\s*(```|~~~)/.test(line)) { fence = !fence; return; }
    if (fence) return;
    const code = line.replace(/`[^`]*`/g, '');
    for (const m of code.matchAll(/!?\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g)) {
      const target = m[1];
      if (/^(https?:|mailto:)/.test(target)) continue;
      checked++;
      const [p, hash] = target.split('#');
      const dest = p ? path.resolve(path.dirname(file), decodeURIComponent(p)) : file;
      if (!fs.existsSync(dest)) { console.log(`${rel}:${i + 1}: missing file ${target}`); bad++; continue; }
      if (hash && dest.endsWith('.md') && !anchors(dest).has(hash)) { console.log(`${rel}:${i + 1}: missing anchor ${target}`); bad++; }
    }
  });
}
if (process.argv.includes('--list')) for (const rel of files) console.log(rel);
console.log(`${checked} relative links checked in ${files.length} files, ${bad} broken`);
process.exit(bad ? 1 : 0);
