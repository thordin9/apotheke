<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;

/** The CI-only fail branch of SkipsOutsideCi (#65 QA N-2). */
final class SkipsOutsideCiTest extends TestCase
{
    use SkipsOutsideCi;

    /** @var array<string,string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['CI', 'GITHUB_ACTIONS'] as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testFailsInCi(): void
    {
        foreach ([['CI', 'true'], ['CI', '1'], ['GITHUB_ACTIONS', 'true']] as [$name, $value]) {
            putenv($name . '=' . $value);
            try {
                $this->skipOutsideCi('setup missing', 'fix it.');
            } catch (AssertionFailedError $e) {
                $this->assertSame('setup missing. In CI this test must run: fix it.', $e->getMessage(), "$name=$value");
            } finally {
                putenv($name);
            }
        }
    }

    public function testSkipsElsewhere(): void
    {
        foreach ([null, ['CI', 'false'], ['CI', 'TRUE'], ['GITHUB_ACTIONS', '1']] as $env) {
            if ($env !== null) {
                putenv($env[0] . '=' . $env[1]);
            }
            try {
                $this->skipOutsideCi('setup missing', 'fix it.');
            } catch (SkippedWithMessageException $e) {
                $this->assertSame('setup missing', $e->getMessage());
            } finally {
                if ($env !== null) {
                    putenv($env[0]);
                }
            }
        }
    }
}
