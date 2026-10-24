<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Ci4DiRuleTest extends TestCase
{
    public function testServiceCallOutsideAControllerIsReported(): void
    {
        $root = dirname(__DIR__, 2);
        if (PHP_VERSION_ID < 80400 || !is_file($root . '/vendor/bin/phpstan') || !is_file($root . '/ci4/vendor/autoload.php')) {
            $this->markTestSkipped('phpstan, the CI4 vendor tree and PHP 8.4 are required');
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/vendor/bin/phpstan')
            . ' analyse -c ' . escapeshellarg($root . '/phpstan-di.neon')
            . ' --no-progress --memory-limit=1G --error-format=raw '
            . escapeshellarg($root . '/ci4/tools/PhpStan/fixtures/BadCall.php')
            . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        $this->assertSame(1, $code, $text);
        $this->assertStringContainsString('service() is only allowed in controllers, filters and Config/Services.php', $text);
        $this->assertStringContainsString('Services:: is only allowed in controllers, filters and Config/Services.php', $text);
    }

    public function testModuleFiltersMayUseServicesInBeforeAndAfter(): void
    {
        [$code, $out] = $this->analyse('ci4/modules/Fixture/Filters/GoodFilter.php');
        $this->assertSame(0, $code, $out);
    }

    public function testFilterStateAndOtherClassesAreStillFlagged(): void
    {
        $cases = [
            // constructor, helper method and a closure inside before()
            'ci4/modules/Fixture/Filters/BadFilterState.php' => 3,
            'ci4/modules/Fixture/Filters/Nested/DeepFilter.php' => 1,
            'ci4/modules/Fixture/Libraries/NotAFilter.php' => 1,
            'Filters/LooseFilter.php' => 1,
        ];
        foreach ($cases as $fixture => $errors) {
            [$code, $out] = $this->analyse($fixture);
            $this->assertSame(1, $code, $fixture . "\n" . $out);
            $this->assertSame($errors, substr_count($out, 'is only allowed in controllers, filters and Config/Services.php'), $fixture . "\n" . $out);
        }
    }

    /** @return array{0:int,1:string} exit code and raw output for one fixture under ci4/tools/PhpStan/fixtures */
    private function analyse(string $fixture): array
    {
        $root = dirname(__DIR__, 2);
        if (PHP_VERSION_ID < 80400 || !is_file($root . '/vendor/bin/phpstan') || !is_file($root . '/ci4/vendor/autoload.php')) {
            $this->markTestSkipped('phpstan, the CI4 vendor tree and PHP 8.4 are required');
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/vendor/bin/phpstan')
            . ' analyse -c ' . escapeshellarg($root . '/phpstan-di.neon')
            . ' --no-progress --memory-limit=1G --error-format=raw '
            . escapeshellarg($root . '/ci4/tools/PhpStan/fixtures/' . $fixture)
            . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);

        return [$code, implode("\n", $out)];
    }
}
