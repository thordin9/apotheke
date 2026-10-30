<?php

declare(strict_types=1);

/**
 * For tests that are the only check on a path: skip locally when the setup is
 * missing, but fail in CI (CI=true/1 or GITHUB_ACTIONS=true, as GitHub sets them).
 */
trait SkipsOutsideCi
{
    private function skipOutsideCi(string $why, string $fix): never
    {
        $ci = in_array(getenv('CI'), ['true', '1'], true) || getenv('GITHUB_ACTIONS') === 'true';
        if ($ci) {
            $this->fail($why . '. In CI this test must run: ' . $fix);
        }
        $this->markTestSkipped($why);
    }

    /** For tests that prove a refused PostgreSQL login: they need a server that checks passwords (N-1301). */
    private function skipUnlessPgRejectsWrongPasswords(): void
    {
        if (!test_pg_rejects_wrong_password()) {
            $this->skipOutsideCi('the test PostgreSQL accepts a wrong password (trust auth), so a refused login cannot be shown',
                'use password (scram-sha-256) auth for TEST_PG_* (docs/testing.md).');
        }
    }
}
