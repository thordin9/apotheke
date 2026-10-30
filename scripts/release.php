<?php
declare(strict_types=1);

/*
 * SemVer release helper (no dependencies). VERSION is the single source of truth.
 *
 *   php scripts/release.php plan [--github-output]   what the next release would be (bump from Conventional Commits
 *                                                    since the last vX.Y.Z tag); prints key=value lines
 *   php scripts/release.php apply --notes=FILE [--date=YYYY-MM-DD]
 *                                                    write VERSION + CHANGELOG.md, put the release notes in FILE
 *   php scripts/release.php check-links              fail when an Unreleased handwritten bullet has no PR link
 *
 * Bump rules: a breaking change (`!` after the type or a `BREAKING CHANGE:` footer) = major, `feat` = minor,
 * `fix`/`perf` = patch. Commits of other types only (docs, test, ci, build, chore, style, refactor) = no release.
 * Release commits (`chore(release): …`) are ignored. Commits are linked to the PR whose merge commit brought them in.
 */

const REL_TYPES = 'feat|fix|docs|style|refactor|perf|test|build|ci|chore';
const REL_GROUPS = [   // Keep a Changelog sections, in order
    'breaking' => 'Breaking changes',
    'feat' => 'Added',
    'fix' => 'Fixed',
    'perf' => 'Performance',
    'refactor' => 'Changed',
    'other' => 'Maintenance',
];

/** @return ?array{type:string,scope:string,breaking:bool,desc:string} null for a subject that isn't a Conventional Commit */
function rel_parse(string $subject, string $body = ''): ?array
{
    if (!preg_match('/^(' . REL_TYPES . ')(?:\(([a-z0-9._\/-]+)\))?(!)?: (.+)$/D', trim($subject), $m)) return null;
    return ['type' => $m[1], 'scope' => $m[2], 'breaking' => $m[3] === '!' || preg_match('/^BREAKING[ -]CHANGE: /m', $body) === 1, 'desc' => trim($m[4])];
}

function rel_is_release(string $subject): bool
{
    return preg_match('/^chore\(release\): v?\d+\.\d+\.\d+/', $subject) === 1;
}

/** @param array<int,array{subject:string,body?:string}> $commits @return ?string major|minor|patch, null = nothing to release */
function rel_bump(array $commits): ?string
{
    $bump = null;
    foreach ($commits as $c) {
        if (rel_is_release($c['subject']) || !($p = rel_parse($c['subject'], $c['body'] ?? ''))) continue;
        if ($p['breaking']) return 'major';
        if ($p['type'] === 'feat') $bump = 'minor';
        elseif (in_array($p['type'], ['fix', 'perf'], true) && $bump === null) $bump = 'patch';
    }
    return $bump;
}

function rel_next(string $version, string $bump): string
{
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/D', trim($version), $m)) throw new InvalidArgumentException("not a version: $version");
    [$ma, $mi, $pa] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    return match ($bump) {
        'major' => ($ma + 1) . '.0.0',
        'minor' => "$ma." . ($mi + 1) . '.0',
        'patch' => "$ma.$mi." . ($pa + 1),
        default => throw new InvalidArgumentException("unknown bump: $bump"),
    };
}

/** The changelog section for one release (without the trailing link reference). */
function rel_section(string $version, string $date, array $commits, string $repoUrl): string
{
    $groups = [];
    foreach ($commits as $c) {
        if (rel_is_release($c['subject']) || !($p = rel_parse($c['subject'], $c['body'] ?? ''))) continue;
        $g = $p['breaking'] ? 'breaking' : (isset(REL_GROUPS[$p['type']]) ? $p['type'] : 'other');
        $line = '- ' . ($p['scope'] !== '' ? '**' . $p['scope'] . ':** ' : '') . ($g === 'other' ? $p['type'] . ': ' : '') . $p['desc'];
        if (!empty($c['pr'])) $line .= ' ([#' . (int)$c['pr'] . '](' . $repoUrl . '/pull/' . (int)$c['pr'] . '))';
        $groups[$g][] = $line;
    }
    $out = "## [$version] - $date\n";
    foreach (REL_GROUPS as $k => $title) {
        if (empty($groups[$k])) continue;
        $out .= "\n### $title\n\n" . implode("\n", array_values(array_unique($groups[$k]))) . "\n";
    }
    return $out;
}

/**
 * Insert a release section below "## [Unreleased]" and add/refresh the compare links at the bottom.
 * Notes written by hand under Unreleased move into that section, and Unreleased is left empty.
 */
function rel_changelog_insert(string $changelog, string $section, string $version, ?string $prevTag, string $repoUrl): string
{
    if (!str_contains($changelog, '## [Unreleased]')) throw new RuntimeException('CHANGELOG.md has no "## [Unreleased]" heading');
    if (preg_match('/^## \[' . preg_quote($version, '/') . '\]/m', $changelog)) throw new RuntimeException("CHANGELOG.md already has $version");
    $parts = preg_split('/^(## \[Unreleased\][^\n]*\n)/m', $changelog, 2, PREG_SPLIT_DELIM_CAPTURE);
    [$head, $unrel, $rest] = $parts;
    $next = preg_match('/^## \[/m', $rest, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : (preg_match('/^\[[^\]]+\]: /m', $rest, $ml, PREG_OFFSET_CAPTURE) ? $ml[0][1] : strlen($rest));
    $hand = substr($rest, 0, $next);
    if (trim($hand) === '') {
        $rest = rtrim($hand) . "\n" . $section . "\n" . substr($rest, $next);
    } else {
        $rest = "\n" . rel_changelog_fold($section, $hand) . "\n" . substr($rest, $next);
    }
    $out = $head . $unrel . $rest;
    $out = preg_replace('/^\[Unreleased\]: .*$/m', '[Unreleased]: ' . $repoUrl . '/compare/v' . $version . '...HEAD', $out, 1, $n);
    if (!$n) $out = rtrim($out) . "\n\n[Unreleased]: $repoUrl/compare/v$version...HEAD\n";
    $link = '[' . $version . ']: ' . $repoUrl . ($prevTag ? '/compare/' . $prevTag . '...v' . $version : '/releases/tag/v' . $version);
    $out = preg_replace('/^(\[Unreleased\]: [^\n]*\n)/m', "$1$link\n", $out, 1);
    return $out;
}

/** Move hand-written Unreleased notes into $section. Same ### headings are joined; others are appended. */
function rel_changelog_fold(string $section, string $hand): string
{
    $notes = [];
    $preface = [];
    $title = '';
    foreach (preg_split("/\n/", str_replace("\r\n", "\n", $hand)) as $line) {
        if (preg_match('/^### (.+)$/', $line, $m)) {
            $title = trim($m[1]);
            $notes[$title] ??= [];
            continue;
        }
        if ($title === '') $preface[] = $line;
        else $notes[$title][] = $line;
    }
    $prefaceText = rel_trim_blank_edges($preface);
    foreach ($notes as $name => $lines) $notes[$name] = rel_trim_blank_edges($lines);
    if (!preg_match('/\A(## [^\n]*)\n(.*)\z/s', $section, $parts)) return rtrim($section) . "\n";
    $chunks = preg_split('/^(### .+)$/m', $parts[2], -1, PREG_SPLIT_DELIM_CAPTURE);
    $lead = trim((string)array_shift($chunks));
    $order = [];
    $content = [];
    for ($i = 0; $i < count($chunks); $i += 2) {
        $name = trim(substr($chunks[$i], 4));
        $text = trim($chunks[$i + 1]);
        if (!isset($content[$name])) $order[] = $name;
        $content[$name] = isset($content[$name]) && $content[$name] !== '' ? $content[$name] . "\n" . $text : $text;
    }
    foreach ($notes as $name => $text) {
        if ($text === '') continue;
        if (!isset($content[$name])) $order[] = $name;
        $prior = $content[$name] ?? '';
        if ($prior !== '' && $name === REL_GROUPS['fix']) $prior = rel_drop_pr_generated($prior, $text);
        $content[$name] = $prior !== '' ? trim(rel_drop_scoped_generated($prior, $text) . "\n" . $text) : $text;
    }
    $out = $parts[1];
    $intro = array_values(array_filter([$lead, $prefaceText], fn($l) => $l !== ''));
    if ($intro !== []) $out .= "\n\n" . implode("\n\n", $intro);
    foreach ($order as $name) {
        if (($content[$name] ?? '') === '') continue;
        $out .= (str_ends_with($out, "\n") ? "\n" : "\n\n") . "### $name\n\n" . $content[$name] . "\n";
    }
    return str_ends_with($out, "\n") ? $out : $out . "\n";
}

/**
 * A handwritten bullet replaces generated bullets in the same section that use its scope
 * (`- **autofill:** …`), so the release notes do not list that change twice.
 */
function rel_drop_scoped_generated(string $generated, string $hand): string
{
    $scopes = [];
    foreach (preg_split("/\n/", $hand) as $line) {
        if (preg_match('/^- \*\*([^*]+):\*\* /', $line, $m)) $scopes[$m[1]] = true;
    }
    if ($scopes === []) return $generated;
    $kept = [];
    foreach (preg_split("/\n/", $generated) as $line) {
        if (preg_match('/^- \*\*([^*]+):\*\* /', $line, $m) && isset($scopes[$m[1]])) continue;
        $kept[] = $line;
    }
    return rel_trim_blank_edges($kept);
}

/**
 * Under Fixed, a handwritten bullet that links a PR replaces the bullets generated from that PR's `fix:` commits,
 * whatever their scope, so a follow-up commit in the PR does not add a second line for the same fix.
 */
function rel_drop_pr_generated(string $generated, string $hand): string
{
    $prs = [];
    if (preg_match_all('/^- .*\(\[#(\d+)\]\([^)\s]+\/pull\/\d+\)\)\s*$/m', $hand, $m)) $prs = array_flip($m[1]);
    if ($prs === []) return $generated;
    $kept = [];
    foreach (preg_split("/\n/", $generated) as $line) {
        if (preg_match('/^- .*\(\[#(\d+)\]\([^)\s]+\/pull\/\d+\)\)\s*$/', $line, $mm) && isset($prs[$mm[1]])) continue;
        $kept[] = $line;
    }
    return rel_trim_blank_edges($kept);
}

/**
 * Handwritten bullets under ## [Unreleased] that have no pull-request link.
 * A link looks like `([#60](https://github.com/thordin9/apotheke/pull/60))`.
 * Released sections are left alone. An empty Unreleased section has none.
 * @return list<string>
 */
function rel_unreleased_missing_pr_links(string $changelog): array
{
    if (!preg_match('/^## \[Unreleased\][^\n]*\n(.*?)(?=^## \[|^\[[^\]]+\]: |\z)/ms', $changelog, $m)) return [];
    $missing = [];
    foreach (preg_split("/\n/", $m[1]) as $line) {
        if (!preg_match('/^- /', $line)) continue;
        if (!preg_match('/\(\[#\d+\]\(https:\/\/github\.com\/[^)\s]+\/pull\/\d+\)\)/', $line)) $missing[] = $line;
    }
    return $missing;
}

/** Drop blank lines at the ends of a block and keep the ones in the middle. */
function rel_trim_blank_edges(array $lines): string
{
    while ($lines !== [] && trim((string)$lines[0]) === '') array_shift($lines);
    while ($lines !== [] && trim((string)$lines[array_key_last($lines)]) === '') array_pop($lines);
    return implode("\n", $lines);
}

/** GitHub release body: the version section after folding, plus the compare link. */
function rel_release_notes(string $changelog, string $version, string $compareUrl): string
{
    $pattern = '/^## \[' . preg_quote($version, '/') . '\][^\n]*\n(.*?)(?=^## \[|^\[[^\]]+\]: |\z)/ms';
    if (!preg_match($pattern, $changelog, $m)) throw new RuntimeException("CHANGELOG.md has no $version section");
    return trim($m[1]) . "\n\n**Full changelog:** $compareUrl\n";
}

/* ---------- git ---------- */

function rel_git(string ...$args): string
{
    $cmd = 'git ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
    exec($cmd, $out, $rc);
    if ($rc !== 0) throw new RuntimeException("git failed: $cmd");
    return implode("\n", $out);
}

function rel_last_tag(): ?string
{
    try { return rel_git('describe', '--tags', '--abbrev=0', '--match', 'v[0-9]*.[0-9]*.[0-9]*', 'HEAD') ?: null; } catch (RuntimeException) { return null; }
}

/** Commits since $tag (oldest first) with the PR number of the merge that brought each one in. */
function rel_commits(?string $tag): array
{
    $range = $tag ? "$tag..HEAD" : 'HEAD';
    $raw = rel_git('log', '--reverse', '--no-merges', '--format=%H%x1f%s%x1f%b%x1e', $range);
    $prOf = [];
    foreach (array_filter(explode("\n", rel_git('log', '--merges', '--first-parent', '--format=%H %s', $range))) as $line) {
        [$sha, $subj] = explode(' ', $line, 2);
        // "Merge pull request #12 from …", or a merge titled like a squash: "subject (#12)".
        if (!preg_match('/^Merge pull request #(\d+)/', $subj, $m) && !preg_match('/\(#(\d+)\)$/', $subj, $m)) continue;
        foreach (array_filter(explode("\n", rel_git('rev-list', "$sha^1..$sha^2"))) as $c) $prOf[$c] ??= (int)$m[1];
    }
    $out = [];
    foreach (array_filter(explode("\x1e", $raw), fn($r) => trim($r) !== '') as $rec) {
        [$sha, $subject, $body] = array_pad(explode("\x1f", ltrim($rec, "\n")), 3, '');
        if (!preg_match('/\(#(\d+)\)$/', $subject, $sq)) $sq = null;   // squash merges: "subject (#12)"
        $out[] = ['sha' => $sha, 'subject' => $sq ? trim(substr($subject, 0, -strlen($sq[0]))) : $subject, 'body' => $body, 'pr' => $prOf[$sha] ?? ($sq ? (int)$sq[1] : null)];
    }
    return $out;
}

function rel_repo_url(): string
{
    $env = getenv('GITHUB_SERVER_URL') && getenv('GITHUB_REPOSITORY') ? getenv('GITHUB_SERVER_URL') . '/' . getenv('GITHUB_REPOSITORY') : '';
    if ($env) return $env;
    $u = rel_git('remote', 'get-url', 'origin');
    $u = preg_replace(['#^git@([^:]+):#', '#\.git$#', '#^https://[^@/]+@#'], ['https://$1/', '', 'https://'], $u);
    return rtrim($u, '/');
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    chdir($root);
    $cmd = $argv[1] ?? '';
    if ($cmd === 'check-links') {
        $missing = rel_unreleased_missing_pr_links((string)file_get_contents("$root/CHANGELOG.md"));
        if ($missing !== []) {
            fwrite(STDERR, "Unreleased bullets need a pull-request link:\n" . implode("\n", $missing) . "\n");
            exit(1);
        }
        exit(0);
    }
    $opt = [];
    foreach (array_slice($argv, 2) as $a) if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
    $current = trim((string)@file_get_contents("$root/VERSION"));
    $tag = rel_last_tag();
    $commits = rel_commits($tag);
    $bump = rel_bump($commits);
    $next = $bump ? rel_next($current, $bump) : '';
    if ($cmd === 'plan') {
        $kv = ['current' => $current, 'last_tag' => (string)$tag, 'commits' => (string)count($commits), 'bump' => (string)$bump, 'next' => $next, 'release' => $bump ? 'true' : 'false'];
        $txt = implode('', array_map(fn($k, $v) => "$k=$v\n", array_keys($kv), $kv));
        echo $txt;
        if (isset($opt['github-output']) && ($go = getenv('GITHUB_OUTPUT'))) file_put_contents($go, $txt, FILE_APPEND);
        exit(0);
    }
    if ($cmd === 'apply') {
        if (!$bump) { fwrite(STDERR, "nothing to release (only commits that don't change the app since " . ($tag ?? 'the start') . ")\n"); exit(3); }
        if ($tag !== null && $tag !== "v$current") { fwrite(STDERR, "VERSION ($current) does not match the last tag ($tag)\n"); exit(4); }
        $date = is_string($opt['date'] ?? null) ? $opt['date'] : gmdate('Y-m-d');
        $url = rel_repo_url();
        $section = rel_section($next, $date, $commits, $url);
        $written = rel_changelog_insert((string)file_get_contents("$root/CHANGELOG.md"), $section, $next, $tag, $url);
        file_put_contents("$root/CHANGELOG.md", $written);
        file_put_contents("$root/VERSION", "$next\n");
        $notes = rel_release_notes($written, $next, $tag ? "$url/compare/$tag...v$next" : "$url/commits/v$next");
        file_put_contents(is_string($opt['notes'] ?? null) ? $opt['notes'] : 'release-notes.md', $notes);
        echo "version=$next\n";
        exit(0);
    }
    fwrite(STDERR, "usage: php scripts/release.php plan [--github-output] | apply --notes=FILE [--date=YYYY-MM-DD] | check-links\n");
    exit(2);
}
