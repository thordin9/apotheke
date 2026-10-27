<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/parity/gate.php';

/**
 * PLAN step 16: the parity gate (tests/parity/gate.php). The full run, legacy against CI4 on this PHP and
 * database, is `php scripts/parity-gate.php` (its own CI job); PARITY_GATE=1 runs it here as well.
 */
final class ParityGateTest extends TestCase
{
    public function testP95IsTheNearestRank(): void
    {
        $this->assertSame(0.0, parity_p95([]));
        $this->assertSame(7.0, parity_p95([7.0]));
        $this->assertSame(95.0, parity_p95(array_map('floatval', range(1, 100))));
        $this->assertSame(19.0, parity_p95(array_map('floatval', range(20, 1, -1))), 'sorted first; rank ceil(0.95 × 20) = 19');
    }

    /** The temporary budget: CI4's p95 within legacy + N ms (8 on SQLite, 20 on PG in CI), next to the +N % bar. */
    public function testTheP95BudgetFailsOnlyWhenExceeded(): void
    {
        $this->assertSame([], parity_p95_over(2.08, 7.08, null, 5.0), 'exactly +5 ms is within budget');
        $this->assertSame(['+5.01 ms > +5 ms'], parity_p95_over(2.0, 7.01, null, 5.0));
        $this->assertSame(['+15.94 ms > +12 ms'], parity_p95_over(10.97, 26.91, null, 12.0));
        $this->assertSame([], parity_p95_over(10.97, 22.97, null, 12.0));
        $this->assertSame([], parity_p95_over(9.0, 3.0, 20.0, 5.0), 'CI4 faster is fine');
        $this->assertSame(['+100% > +20%'], parity_p95_over(2.0, 4.0, 20.0, 5.0), 'both bounds apply: +2 ms but +100 %');
        $this->assertSame(['+150% > +20%', '+6.00 ms > +5 ms'], parity_p95_over(4.0, 10.0, 20.0, 5.0));
        $this->assertSame([], parity_p95_over(10.0, 12.0, 20.0, null), 'exactly +20 % is within budget');
        $this->assertSame([], parity_p95_over(1.0, 50.0, null, null), 'no limit set: report only');
        $this->assertSame([], parity_p95_over(0.0, 3.0, 20.0, 5.0), 'no legacy samples: the % bound cannot apply');
    }

    /** The PM's fallback: the absolute budget may compare medians instead of p95. */
    public function testTheBudgetStatisticIsP95OrMedian(): void
    {
        $this->assertSame(3.0, parity_median([5.0, 1.0, 3.0]));
        $this->assertSame(2.0, parity_median([4.0, 1.0, 3.0, 2.0]), 'nearest rank: the lower middle');
        $this->assertSame(0.0, parity_median([]));
        $this->assertSame('p95', parity_budget_stat(false));
        $this->assertSame('p95', parity_budget_stat(''));
        $this->assertSame('p95', parity_budget_stat('p95'));
        $this->assertSame('median', parity_budget_stat(' Median '));
        $this->expectException(InvalidArgumentException::class);
        parity_budget_stat('p50');
    }

    public function testP95LimitsComeFromTheEnvironment(): void
    {
        $this->assertNull(parity_p95_limit(false, 'X'));
        $this->assertNull(parity_p95_limit('', 'X'));
        $this->assertNull(parity_p95_limit('  ', 'X'));
        $this->assertSame(5.0, parity_p95_limit('5', 'X'));
        $this->assertSame(12.5, parity_p95_limit(' 12.5 ', 'X'));
        $this->assertSame(0.0, parity_p95_limit('0', 'X'));
        foreach (['5ms', 'abc', '-1'] as $bad) {
            try {
                parity_p95_limit($bad, 'PARITY_P95_MAX_ABS_MS');
                $this->fail("'$bad' must not switch the guard off");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('PARITY_P95_MAX_ABS_MS', $e->getMessage());
            }
        }
    }

    /** Legacy's answer to POST /theme, as the gate saw it (303, theme cookie, the security headers). */
    private const THEME_303 = [
        'HTTP/1.1 303 See Other', 'Date: Tue, 27 Oct 2026 06:40:56 GMT', 'X-Content-Type-Options: nosniff',
        'X-Frame-Options: SAMEORIGIN', 'Referrer-Policy: same-origin',
        "Content-Security-Policy: default-src 'self'; frame-ancestors 'self'", 'Expires: Thu, 19 Nov 1981 08:52:00 GMT',
        'Cache-Control: no-store, no-cache, must-revalidate', 'Pragma: no-cache',
        'Set-Cookie: theme=dark; expires=Wed, 01 Dec 2027 06:40:56 GMT; Max-Age=34560000; path=/; SameSite=Lax',
        'Set-Cookie: apothekesid=0123456789abcdef0123456789abcdef; expires=Thu, 26 Nov 2026 06:40:56 GMT; Max-Age=2592000; path=/; HttpOnly; SameSite=Lax',
        'Location: /',
    ];

    /** @param list<string> $raw */
    private static function headers(array $raw, int $status = 303, bool $json = false): array
    {
        return parity_gate_headers($status, $json, $raw, static fn (string $s): string => $s);
    }

    /** @param list<string> $raw */
    private static function swap(array $raw, string $from, string $to): array
    {
        $out = array_map(static fn (string $l): string => str_replace($from, $to, $l), $raw);
        if ($out === $raw && $from !== '') throw new LogicException("'$from' not in the fixture");
        return array_values(array_filter($out, static fn (string $l): bool => $l !== ''));
    }

    /** M-470: every change QA injected must show up as a difference. */
    public function testInjectedHeaderChangesFailTheGate(): void
    {
        $legacy = self::headers(self::THEME_303);
        $this->assertSame([], parity_diff($legacy, self::headers(self::THEME_303)));
        $injected = [
            'theme SameSite=None' => self::swap(self::THEME_303, 'path=/; SameSite=Lax', 'path=/; SameSite=None'),
            'theme wrong value' => self::swap(self::THEME_303, 'theme=dark', 'theme=light'),
            'theme Secure added' => self::swap(self::THEME_303, 'Max-Age=34560000;', 'Max-Age=34560000; Secure;'),
            'theme 30 days' => self::swap(self::THEME_303, 'expires=Wed, 01 Dec 2027', 'expires=Thu, 26 Nov 2026'),
            'session without HttpOnly' => self::swap(self::THEME_303, ' HttpOnly;', ''),
            'session other Path' => self::swap(self::THEME_303, 'Max-Age=2592000; path=/;', 'Max-Age=2592000; path=/app;'),
            'session Domain added' => self::swap(self::THEME_303, 'Max-Age=2592000;', 'Max-Age=2592000; Domain=example.test;'),
            'session ID shorter' => self::swap(self::THEME_303, 'apothekesid=0123456789abcdef0123456789abcdef', 'apothekesid=0123456789abcdef'),
            'X-Frame-Options changed' => self::swap(self::THEME_303, 'X-Frame-Options: SAMEORIGIN', 'X-Frame-Options: ALLOWALL'),
            'nosniff removed' => self::swap(self::THEME_303, 'X-Content-Type-Options: nosniff', ''),
            'Referrer-Policy changed' => self::swap(self::THEME_303, 'Referrer-Policy: same-origin', 'Referrer-Policy: unsafe-url'),
            'CSP changed' => self::swap(self::THEME_303, "frame-ancestors 'self'", "frame-ancestors *"),
            'Permissions-Policy added' => [...self::THEME_303, 'Permissions-Policy: camera=*'],
            'HSTS added' => [...self::THEME_303, 'Strict-Transport-Security: max-age=1'],
            'public max-age on a redirect' => self::swap(self::THEME_303, 'Cache-Control: no-store, no-cache, must-revalidate', 'Cache-Control: public, max-age=3600'),
            'Pragma removed' => self::swap(self::THEME_303, 'Pragma: no-cache', ''),
        ];
        foreach ($injected as $what => $raw) {
            $this->assertNotSame([], parity_diff($legacy, self::headers($raw)), $what);
        }
        $error = self::swap(self::THEME_303, 'HTTP/1.1 303 See Other', 'HTTP/1.1 404 Not Found');
        $public = self::swap($error, 'Cache-Control: no-store, no-cache, must-revalidate', 'Cache-Control: public, max-age=3600');
        $this->assertNotSame([], parity_diff(self::headers($error, 404), self::headers($public, 404)), 'public max-age on an error');
    }

    /** M-470: what legitimately differs per run compares by shape, and the attribute order does not matter. */
    public function testPerRunCookieValuesCompareByShape(): void
    {
        $legacy = self::headers(self::THEME_303);
        $other = self::swap(self::THEME_303, 'apothekesid=0123456789abcdef0123456789abcdef', 'apothekesid=fedcba9876543210fedcba9876543210');
        $other = self::swap($other, '06:40:56 GMT', '06:40:57 GMT');
        $other = self::swap($other, 'path=/; SameSite=Lax', 'SameSite=Lax; Path=/');
        $this->assertSame([], parity_diff($legacy, self::headers($other)));
        $this->assertContains('apothekesid=<32 hex>; expires=+30d; httponly; max-age=2592000; path=/; samesite=Lax', $legacy['cookies']);
        $this->assertSame('x=deleted; expires=past', parity_gate_cookie('x=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT', 1000, 'strval'));
        $this->assertSame('csrf_x=<64 hex>; expires=+20min', parity_gate_cookie('csrf_x=' . str_repeat('a1', 32) . '; expires=Tue, 27 Oct 2026 07:00:56 GMT', (int)strtotime('Tue, 27 Oct 2026 06:40:56 GMT'), 'strval'));
    }

    /** L-471: only the documented Cache-Control pair is equal, and only on a non-JSON answer other than 200. */
    public function testOnlyTheDocumentedCacheControlPairIsEqual(): void
    {
        $ci4 = self::swap(self::THEME_303, 'Cache-Control: no-store, no-cache, must-revalidate', 'Cache-Control: no-store, max-age=0, no-cache');
        $this->assertSame([], parity_diff(self::headers(self::THEME_303), self::headers($ci4)));
        $this->assertNotSame([], parity_diff(self::headers(self::THEME_303, 200), self::headers($ci4, 200)), 'a 200 compares exactly');
        $this->assertNotSame([], parity_diff(self::headers(self::THEME_303, 303, true), self::headers($ci4, 303, true)), 'JSON compares exactly');
        foreach (['no-store', 'no-store, no-cache', 'no-store, max-age=0', 'no-cache, must-revalidate', 'private, no-store'] as $near) {
            $raw = self::swap(self::THEME_303, 'Cache-Control: no-store, no-cache, must-revalidate', 'Cache-Control: ' . $near);
            $this->assertNotSame([], parity_diff(self::headers(self::THEME_303), self::headers($raw)), $near);
        }
    }

    /** L-471: a stored password hash keeps its algorithm and cost; only the salt and the hash are masked. */
    public function testPasswordHashesKeepAlgorithmAndCost(): void
    {
        $a = password_hash('long-enough', PASSWORD_BCRYPT, ['cost' => 12]);
        $b = password_hash('something-else', PASSWORD_BCRYPT, ['cost' => 12]);
        $this->assertSame('$2y$12$…(53)', parity_gate_hash($a));
        $this->assertSame(parity_gate_hash($a), parity_gate_hash($b));
        $this->assertSame('$2y$04$…(53)', parity_gate_hash(password_hash('long-enough', PASSWORD_BCRYPT, ['cost' => 4])));
        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertMatchesRegularExpression('/^\$argon2id\$v=19\$m=\d+,t=\d+,p=\d+\$…$/', parity_gate_hash(password_hash('x', PASSWORD_ARGON2ID)));
        }
        $this->assertSame('plain', parity_gate_hash('plain'));
    }

    public function testTheAllowlistStillHoldsOnlyTheFourQuirks(): void
    {
        $this->assertSame(['N-2', 'QA L-2', 'N-4', 'I-3'], array_column(parity_allowlist_load(), 'id'));
    }

    /** CI4's autofill cache is compared under legacy's names; nothing else is renamed. */
    public function testOnlyTheAutofillCacheIsComparedUnderLegacyNames(): void
    {
        $this->assertSame(['autofill/info.json' => 'autofill-info.json', 'autofill/tmp' => 'tmp'], PARITY_GATE_LAYOUT);
        $dir = tmpdir('parity-gate-files');
        try {
            mkdir($dir . '/autofill/tmp', 0700, true);
            file_put_contents($dir . '/autofill/info.json', '{"at":1,"url":"x"}');
            mkdir($dir . '/files', 0750);
            file_put_contents($dir . '/files/' . str_repeat('ab', 8) . 'cd' . str_repeat('ef', 7) . '.pdf', '%PDF');
            $names = [];
            $hide = static function (string $s) use (&$names): string {
                return (string)preg_replace_callback('/[0-9a-f]{32}/', static fn (array $m): string => 'H' . ($names[$m[0]] ??= count($names) + 1), $s);
            };
            $files = parity_gate_files($dir, $hide);
            $this->assertSame('0644 {"at":T,"url":"x"}', preg_replace('/^\d{4}/', '0644', $files['autofill-info.json']));
            $this->assertArrayHasKey('tmp', $files);
            $this->assertArrayNotHasKey('autofill', $files);
            $this->assertArrayHasKey('files/H1.pdf', $files);
        } finally {
            rrmdir($dir);
        }
    }

    public function testTheGateFindsNoDifferences(): void
    {
        if (getenv('PARITY_GATE') !== '1') {
            $this->markTestSkipped('PARITY_GATE=1 runs the full gate (CI: the parity-gate job runs scripts/parity-gate.php)');
        }
        try {
            $gate = parity_gate(0);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'skip') $this->markTestSkipped('needs PHP 8.4 and ci4/vendor');
            throw $e;
        }
        $this->assertGreaterThanOrEqual(196, $gate['steps']);
        $this->assertSame([], $gate['diffs'], json_encode($gate['diffs'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertSame(['autofill: settings test', 'autofill: settings test photo'], array_keys($gate['pending']));
    }
}
