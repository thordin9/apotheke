<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Public branding routes, pinned to the answers they gave against the legacy front controller:
 * /theme.css, /labels.css, /manifest.webmanifest, /favicon.ico and
 * /brand/{file}. Body bytes and the relevant headers must be identical.
 */
final class Ci4BrandingHttpTest extends TestCase
{
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $seen = [];

    /** Recorded at the cutover, where the legacy comparison still passed (content-length left out). */
    private const GOLDEN = [
        'defaults' => [
            '/theme.css' => [
                'status' => 200,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'no-cache',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/theme.css?v=1' => [
                'status' => 200,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/theme.css?v' => [
                'status' => 200,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/labels.css' => [
                'status' => 200,
                'sha1' => '7d32205e2fb39e88f5116e252c3708cf5ce3f279',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'no-cache',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/labels.css?v=abc' => [
                'status' => 200,
                'sha1' => '7d32205e2fb39e88f5116e252c3708cf5ce3f279',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/manifest.webmanifest' => [
                'status' => 200,
                'sha1' => '278bc84ee1971e7b87d5d5109609e9fbcecfc0a7',
                'content-type' => 'application/manifest+json',
                'cache-control' => 'no-cache',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/favicon.ico' => [
                'status' => 200,
                'sha1' => '17f9ffc0501c32976c4df50b52f004248aae60dd',
                'content-type' => 'image/vnd.microsoft.icon',
                'cache-control' => 'public, max-age=86400',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/logo.svg' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/logo.png' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/icon-192.png' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/favicon.ico' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/evil.php' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
        ],
        'svg logo' => [
            '/theme.css' => [
                'status' => 200,
                'sha1' => '0770c5bc6a5b5450aea8fa55995847ec0a21e743',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'no-cache',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/theme.css?v=1' => [
                'status' => 200,
                'sha1' => '0770c5bc6a5b5450aea8fa55995847ec0a21e743',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/theme.css?v' => [
                'status' => 200,
                'sha1' => '0770c5bc6a5b5450aea8fa55995847ec0a21e743',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/labels.css' => [
                'status' => 200,
                'sha1' => '3c6241ff991c1b1fd786cd5aef64b17997d0aa1b',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'no-cache',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/labels.css?v=abc' => [
                'status' => 200,
                'sha1' => '3c6241ff991c1b1fd786cd5aef64b17997d0aa1b',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/manifest.webmanifest' => [
                'status' => 200,
                'sha1' => '5cc1bde092dd618c6d140cde67f107c4cc736e5a',
                'content-type' => 'application/manifest+json',
                'cache-control' => 'no-cache',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/favicon.ico' => [
                'status' => 200,
                'sha1' => '17f9ffc0501c32976c4df50b52f004248aae60dd',
                'content-type' => 'image/vnd.microsoft.icon',
                'cache-control' => 'public, max-age=86400',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/logo.svg' => [
                'status' => 200,
                'sha1' => 'fca669b5c896ace90fd30f7a45ef216db8433ba9',
                'content-type' => 'image/svg+xml',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'none\'; style-src \'unsafe-inline\'; sandbox',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/logo.png' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/icon-192.png' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/favicon.ico' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/evil.php' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
        ],
        'png logo' => [
            '/theme.css' => [
                'status' => 200,
                'sha1' => 'e27c6c74fcf3bab8ab7948b084ae8b7a387d06cc',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'no-cache',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/theme.css?v=1' => [
                'status' => 200,
                'sha1' => 'e27c6c74fcf3bab8ab7948b084ae8b7a387d06cc',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/theme.css?v' => [
                'status' => 200,
                'sha1' => 'e27c6c74fcf3bab8ab7948b084ae8b7a387d06cc',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/labels.css' => [
                'status' => 200,
                'sha1' => '3c6241ff991c1b1fd786cd5aef64b17997d0aa1b',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'no-cache',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/labels.css?v=abc' => [
                'status' => 200,
                'sha1' => '3c6241ff991c1b1fd786cd5aef64b17997d0aa1b',
                'content-type' => 'text/css; charset=utf-8',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/manifest.webmanifest' => [
                'status' => 200,
                'sha1' => 'fd033e12f2e3d0cb4adf52cd2e72ed19fc44e013',
                'content-type' => 'application/manifest+json',
                'cache-control' => 'no-cache',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/favicon.ico' => [
                'status' => 200,
                'sha1' => '1d8230b89116f3921f2b39d1ac2301ad8e6cbf8a',
                'content-type' => 'image/vnd.microsoft.icon',
                'cache-control' => 'public, max-age=86400',
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/logo.svg' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/logo.png' => [
                'status' => 200,
                'sha1' => 'e168a4590b63ed66e2f54ff428e506b79384ab27',
                'content-type' => 'image/png',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'none\'; style-src \'unsafe-inline\'; sandbox',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/icon-192.png' => [
                'status' => 200,
                'sha1' => 'e168a4590b63ed66e2f54ff428e506b79384ab27',
                'content-type' => 'image/png',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'none\'; style-src \'unsafe-inline\'; sandbox',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/favicon.ico' => [
                'status' => 200,
                'sha1' => '1d8230b89116f3921f2b39d1ac2301ad8e6cbf8a',
                'content-type' => 'image/vnd.microsoft.icon',
                'cache-control' => 'public, max-age=31536000, immutable',
                'content-length' => null,
                'content-security-policy' => 'default-src \'none\'; style-src \'unsafe-inline\'; sandbox',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
            '/brand/evil.php' => [
                'status' => 404,
                'sha1' => 'da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'content-type' => 'text/html; charset=UTF-8',
                'cache-control' => null,
                'content-length' => null,
                'content-security-policy' => 'default-src \'self\'; connect-src \'self\' blob:; img-src \'self\' blob: data:; style-src \'self\'; script-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'',
                'x-content-type-options' => 'nosniff',
                'x-frame-options' => 'SAMEORIGIN',
                'referrer-policy' => 'same-origin',
            ],
        ],
    ];

    private const HEADERS = ['content-type', 'cache-control', 'content-length', 'content-security-policy',
        'x-content-type-options', 'x-frame-options', 'referrer-policy'];

    public function testBrandingRoutes(): void
    {
        if (!is_file(ROOT . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $rightDir = tmpdir('brand-ci4');
        $ci4 = new Server($rightDir, [], 'ci4');
        try {
            $paths = ['/theme.css', '/theme.css?v=1', '/theme.css?v', '/labels.css', '/labels.css?v=abc', '/manifest.webmanifest',
                '/favicon.ico', '/brand/logo.svg', '/brand/logo.png', '/brand/icon-192.png', '/brand/favicon.ico', '/brand/evil.php'];
            $this->compare($ci4, $paths, 'defaults');

            $settings = ['accent' => '"#C8502D"', 'app_name' => '"Αποθήκη του σπιτιού μας"', 'app_tagline' => '"τα πράγματά μας"',
                'label_width_mm' => '50', 'label_height_mm' => '40', 'label_columns' => '4', 'label_gap_mm' => '0',
                'label_show_sub' => 'false', 'label_show_url' => 'true', 'label_border' => 'false', 'icon_background' => '"#112233"'];
            $svg = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 10 10\"><rect width=\"10\" height=\"10\"/></svg>\n";
            foreach ([$rightDir] as $dir) {
                $this->store($dir, $settings + ['logo' => '"svg"']);
                $this->brandFile($dir, 'logo.svg', $svg);
            }
            $this->compare($ci4, $paths, 'svg logo');
            $logo = $ci4->client()->get('/brand/logo.svg');
            $this->assertSame($svg, $logo['body']);
            $this->assertSame("default-src 'none'; style-src 'unsafe-inline'; sandbox", $logo['headers']['content-security-policy']);

            $png = img_png(64, 64);
            $ico = "\0\0\1\0" . str_repeat('i', 40);
            foreach ([$rightDir] as $dir) {
                @unlink($dir . '/brand/logo.svg');
                $this->store($dir, ['logo' => '"png"', 'accent' => '"#0f766e"']);
                foreach (['logo.png', 'icon-32.png', 'icon-180.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png'] as $name) {
                    $this->brandFile($dir, $name, $png);
                }
                $this->brandFile($dir, 'favicon.ico', $ico);
            }
            $this->compare($ci4, $paths, 'png logo');
            $this->assertSame($ico, $ci4->client()->get('/favicon.ico')['body']);
            $manifest = $ci4->client()->get('/manifest.webmanifest')['json'];
            $this->assertStringStartsWith('/brand/icon-192.png?v=', $manifest['icons'][0]['src']);

            // HEAD is answered like GET (headers only).
            $head = $ci4->client()->req('HEAD', '/labels.css');
            $this->assertSame(200, $head['status']);
            $this->assertSame('text/css; charset=utf-8', $head['headers']['content-type']);
            $this->assertSame('', $head['body']);
            // Names outside the legacy pattern are not branding routes: an ordinary 404 page with a session.
            $upper = $ci4->client()->get('/brand/Logo.svg');
            $this->assertSame(404, $upper['status']);
            $this->assertSame(self::GOLDEN, $this->seen);
        } finally {
            $ci4->stop();
            testdb_drop($rightDir);
            rrmdir($rightDir);
            @unlink($rightDir . '.server.log');
        }
    }

    /** @param list<string> $paths */
    private function compare(Server $ci4, array $paths, string $label): void
    {
        foreach ($paths as $path) {
            $right = $ci4->client()->get($path);
            // The cache busters come from file times and hashes: only their places are pinned.
            $busters = static fn (string $body): string => (string)preg_replace('#\?v=[0-9a-f]{8,}#', '?v=V', $body);
            $seen = ['status' => $right['status'], 'sha1' => sha1($busters($right['body']))];
            foreach (self::HEADERS as $name) {
                $seen[$name] = $name === 'content-length' ? null : ($right['headers'][$name] ?? null);
            }
            $this->seen[$label][$path] = $seen;
            $this->assertArrayNotHasKey('set-cookie', $right['headers'], "$label $path cookie");
        }
    }

    /** @param array<string,string> $values name => JSON */
    private function store(string $dir, array $values): void
    {
        $db = testdb($dir);
        $st = $db->prepare('UPDATE settings SET value = ? WHERE name = ?');
        foreach ($values as $name => $json) {
            $st->execute([$json, $name]);
        }
    }

    /** Same bytes and the same mtime on both sides, so the ?v= cache buster matches. */
    private function brandFile(string $dir, string $name, string $bytes): void
    {
        @mkdir($dir . '/brand', 0775, true);
        file_put_contents($dir . '/brand/' . $name, $bytes);
        touch($dir . '/brand/' . $name, 1700000000);
    }
}
