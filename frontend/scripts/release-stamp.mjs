// Stamps {version} and {date} at build time. No dependencies.
// Version comes from the repo-root VERSION file.
// Date is the git tag date (creatordate of refs/tags/v<version>: the tagger
// date of an annotated tag). If that tag is not in the clone, the build date
// (UTC, YYYY-MM-DD) is used.

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

export function readRelease(repoRoot) {
    const version = readFileSync(resolve(repoRoot, 'VERSION'), 'utf8').trim();
    return { version, date: tagDate(repoRoot, version) };
}

function tagDate(repoRoot, version) {
    const tag = `v${version}`;
    const fromRef = git(repoRoot, ['for-each-ref', '--format=%(creatordate:short)', `refs/tags/${tag}`]);
    const dated = fromRef.split('\n').map((line) => line.trim()).find(Boolean);
    if (dated) return dated;
    const fromLog = git(repoRoot, ['log', '-1', '--format=%cs', tag]).trim();
    if (fromLog) return fromLog;
    return new Date().toISOString().slice(0, 10);
}

function git(cwd, args) {
    try {
        return execFileSync('git', args, {
            cwd,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', 'ignore'],
        });
    } catch {
        return '';
    }
}
