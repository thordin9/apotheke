<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The image base is a full PHP patch tag, not a floating major.minor tag. */
final class DockerfileTest extends TestCase
{
    public function testFromLinePinsAFullPatchTag(): void
    {
        $docker = (string)file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
        $this->assertMatchesRegularExpression('/^FROM php:\d+\.\d+\.\d+-fpm-alpine\s*$/m', $docker);
        $this->assertMatchesRegularExpression('/^FROM php:8\.3\.36-fpm-alpine\s*$/m', $docker);
        $this->assertDoesNotMatchRegularExpression('/^FROM php:\d+\.\d+-fpm-alpine\s*$/m', $docker);
    }
}
