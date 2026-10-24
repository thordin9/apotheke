<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/imagick-policy-check.php';

/** scripts/imagick-policy-check.php: delegate and "@file" path rules in `magick -list policy` (#73 QA N-1). */
final class ImagickPolicyCheckTest extends TestCase
{
    private const BASE = "Path: /etc/ImageMagick-7/policy.xml\n"
        . "  Policy: Resource\n    name: time\n    value: 60\n"
        . "  Policy: Delegate\n    rights: None \n    pattern: *\n"
        . "  Policy: Coder\n    rights: None \n    pattern: {MVG,MSL,TEXT,TXT}\n"
        . "  Policy: Path\n    rights: None \n    pattern: @*\n"
        . "\nPath: [built-in]\n  Policy: Undefined\n    rights: None \n";

    /** @return list<bool> */
    private function oks(string $listing): array
    {
        return array_map(static fn(array $c): bool => $c[0], imagick_policy_rights_checks(imagick_policy_blocks($listing)));
    }

    public function testTheShippedRulesPass(): void
    {
        $this->assertSame([true, true], $this->oks(self::BASE));
    }

    public function testANarrowerAllowRuleFails(): void
    {
        $delegate = self::BASE . "  Policy: Delegate\n    rights: Read Write \n    pattern: gs\n";
        $this->assertSame([false, true], $this->oks($delegate));
        $path = self::BASE . "  Policy: Path\n    rights: Read \n    pattern: @/tmp/*\n";
        $this->assertSame([true, false], $this->oks($path));
        $checks = imagick_policy_rights_checks(imagick_policy_blocks($path));
        $this->assertStringContainsString('@/tmp/* Read', $checks[1][1]);
    }

    public function testTheBlanketRulesMustBeThereAndRefuse(): void
    {
        $this->assertSame([false, true], $this->oks(str_replace("  Policy: Delegate\n    rights: None \n    pattern: *\n", '', self::BASE)));
        $this->assertSame([true, false], $this->oks(str_replace("rights: None \n    pattern: @*", "rights: Read \n    pattern: @*", self::BASE)));
        $this->assertSame([false, false], $this->oks(''));
    }
}
