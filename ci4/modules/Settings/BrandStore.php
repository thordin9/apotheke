<?php

declare(strict_types=1);

namespace Apotheke\Settings;

/**
 * Where the uploaded logo and the icons generated from it live. Local disk
 * today (DATA_DIR/brand); an object store such as R2 in the Cloudflare phase.
 * Only the names in FILES exist.
 */
interface BrandStore
{
    /** Allowed names => Content-Type (legacy BRAND_FILES). */
    public const FILES = [
        'logo.svg' => 'image/svg+xml', 'logo.png' => 'image/png', 'icon-32.png' => 'image/png', 'icon-180.png' => 'image/png',
        'icon-192.png' => 'image/png', 'icon-512.png' => 'image/png', 'icon-maskable-512.png' => 'image/png',
        'favicon.ico' => 'image/vnd.microsoft.icon',
    ];

    public function has(string $name): bool;

    /** The bytes, or null when the file is missing or the name is not allowed. */
    public function read(string $name): ?string;

    /** Size in bytes, or null when missing. */
    public function size(string $name): ?int;

    /** Last change as a Unix time, or null when missing. Feeds the ?v= cache buster. */
    public function modifiedAt(string $name): ?int;

    /** Write one file. Throws a RuntimeException when it cannot be stored. */
    public function put(string $name, string $bytes): void;

    /** Remove every brand file (legacy brand_reset() without the setting). */
    public function clear(): void;
}
