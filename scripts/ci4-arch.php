<?php
declare(strict_types=1);

/**
 * deptrac and the DI rule.
 *
 * Both configs are empty until CI4 modules exist. An empty config is a clean
 * pass: deptrac has no layers, and the PHPStan DI config has no paths (PHPStan
 * itself rejects an empty path list, so this script does not invoke it yet).
 * Once a config gains real content, the matching tool must be on PATH.
 */

function ci4_arch_strip(string $text): string
{
    $lines = [];
    foreach (preg_split("/\R/", $text) ?: [] as $line) {
        if (preg_match('/^\s*#/', $line)) continue;
        if (trim($line) === '') continue;
        $lines[] = rtrim($line);
    }
    return implode("\n", $lines);
}

function ci4_deptrac_is_empty(string $yaml): bool
{
    return ci4_arch_strip($yaml) === "deptrac:\n  paths: []\n  layers: []\n  ruleset: {}";
}

function ci4_di_is_empty(string $neon): bool
{
    return ci4_arch_strip($neon) === "parameters:\n    level: 6\n    paths: []";
}

function ci4_bin_exists(string $bin): bool
{
    $path = getenv('PATH') ?: '';
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
        if ($dir === '') continue;
        $file = $dir . DIRECTORY_SEPARATOR . $bin;
        if (is_file($file) && is_executable($file)) return true;
    }
    return false;
}

/**
 * @return array{action: string, exit: int, message: string, command: list<string>}
 */
function ci4_arch_plan(string $which, string $text, bool $toolInstalled, string $configPath): array
{
    if ($which !== 'deptrac' && $which !== 'di') {
        throw new InvalidArgumentException('which must be deptrac or di');
    }
    $empty = $which === 'deptrac' ? ci4_deptrac_is_empty($text) : ci4_di_is_empty($text);
    if ($empty) {
        $message = $which === 'deptrac'
            ? 'deptrac: empty config (no paths, no layers, empty ruleset). Pass. The graph is checked once layers are listed.'
            : 'DI rule: empty PHPStan config (level 6, no paths). Pass. service() and Services:: are checked once paths point at ci4/.';
        return ['action' => 'pass', 'exit' => 0, 'message' => $message, 'command' => []];
    }
    $tool = $which === 'deptrac' ? 'deptrac' : 'phpstan';
    if (!$toolInstalled) {
        $message = $which === 'deptrac'
            ? 'deptrac is not on PATH. The config is no longer empty, so this job must run deptrac.'
            : 'phpstan is not on PATH. The config is no longer empty, so this job must run the DI rule.';
        return ['action' => 'fail', 'exit' => 1, 'message' => $message, 'command' => []];
    }
    $command = $which === 'deptrac'
        ? ['deptrac', 'analyse', '--config-file=' . $configPath]
        : ['phpstan', 'analyse', '-c', $configPath, '--no-progress'];
    return ['action' => 'run', 'exit' => 0, 'message' => "running $tool", 'command' => $command];
}

/** @return array{0: int, 1: string} */
function ci4_arch_check(string $which, string $path): array
{
    if (!is_file($path)) return [1, "missing config $path"];
    $text = (string)file_get_contents($path);
    $tool = $which === 'deptrac' ? 'deptrac' : 'phpstan';
    $plan = ci4_arch_plan($which, $text, ci4_bin_exists($tool), $path);
    if ($plan['action'] !== 'run') return [$plan['exit'], $plan['message']];
    $cmd = implode(' ', array_map('escapeshellarg', $plan['command']));
    exec($cmd . ' 2>&1', $out, $code);
    $msg = trim(implode("\n", $out));
    return [$code, $msg !== '' ? $msg : $plan['message']];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $which = $argv[1] ?? '';
    if ($which !== 'deptrac' && $which !== 'di') {
        fwrite(STDERR, "usage: php scripts/ci4-arch.php deptrac|di\n");
        exit(2);
    }
    $file = $which === 'deptrac' ? 'deptrac.yaml' : 'phpstan-di.neon';
    [$code, $message] = ci4_arch_check($which, dirname(__DIR__) . '/' . $file);
    echo $message . "\n";
    exit($code);
}
