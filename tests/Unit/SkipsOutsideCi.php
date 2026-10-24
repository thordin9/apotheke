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
}
