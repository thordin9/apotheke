<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\PostTooLarge;
use CodeIgniter\Test\CIUnitTestCase;

/** Only a POST past post_max_size counts as the dropped body; a small body without form fields does not. */
final class PostTooLargeTest extends CIUnitTestCase
{
    public function testOnlyALengthPastTheLimitIsTooLarge(): void
    {
        $check = new PostTooLarge();
        $this->assertTrue($check->triggered('POST', [], [], 1025, 1024));
        $this->assertTrue($check->triggered('post', [], [], 1025, 1024));
        $this->assertFalse($check->triggered('POST', [], [], 1024, 1024), 'at the limit');
        $this->assertFalse($check->triggered('POST', [], [], 7, 1024), 'a small JSON or text body');
        $this->assertFalse($check->triggered('POST', [], [], 0, 1024));
        $this->assertFalse($check->triggered('POST', ['a' => '1'], [], 2048, 1024), 'PHP kept the fields');
        $this->assertFalse($check->triggered('POST', [], ['f' => []], 2048, 1024));
        $this->assertFalse($check->triggered('PUT', [], [], 2048, 1024));
        $this->assertFalse($check->triggered('POST', [], [], 2048, 0), 'no limit');
        $this->assertSame(ini_parse_quantity((string)ini_get('post_max_size')), PostTooLarge::limit());
    }
}
