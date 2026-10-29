<?php

declare(strict_types=1);

namespace Apotheke\Core\Security;

/**
 * The at-rest key for recoverable secrets (S-L5), resolved like legacy
 * secret_key_state() in 2.x secretbox.php: a non-empty APP_KEY wins over
 * APP_KEY_FILE, even when it does not decode. A configured key that cannot be
 * used has a problem and no key; no key configured has neither.
 *
 * Problems: malformed | malformed_file | unreadable | inside_data | sodium.
 * Holds no key material beyond the instance; never logs or prints it.
 */
final class SecretKey
{
    public const KEY_BYTES = 32;

    private function __construct(public readonly ?string $key, public readonly ?string $problem)
    {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function usable(string $key): self
    {
        return new self($key, null);
    }

    public static function unusable(string $problem): self
    {
        return new self(null, $problem);
    }

    /**
     * Whether libsodium can seal and open. APOTHEKE_SODIUM=0 is the legacy test seam.
     *
     * @param array<string,mixed> $env
     */
    public static function sodiumAvailable(array $env): bool
    {
        if (($env['APOTHEKE_SODIUM'] ?? null) === '0') {
            return false;
        }

        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_secretbox_open')
            && function_exists('sodium_crypto_secretbox_keygen');
    }

    /** 32 raw key bytes, or null when $raw is not base64 of that length (optional "base64:" prefix). */
    public static function bytes(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, 'base64:')) {
            $raw = trim(substr($raw, 7));
        }
        $bin = base64_decode($raw, true);

        return is_string($bin) && strlen($bin) === self::KEY_BYTES ? $bin : null;
    }

    /**
     * @param array<string,mixed> $env APP_KEY, APP_KEY_FILE, APOTHEKE_SODIUM
     * @param list<string> $backupRoots the data directory and every directory a backup can contain
     */
    public static function fromEnvironment(array $env, array $backupRoots): self
    {
        $sodium = self::sodiumAvailable($env);
        $appKey = $env['APP_KEY'] ?? null;
        if (is_string($appKey) && $appKey !== '') {
            $key = self::bytes($appKey);
            if ($key === null) {
                return self::unusable('malformed');
            }

            return $sodium ? self::usable($key) : self::unusable('sodium');
        }
        $file = $env['APP_KEY_FILE'] ?? null;
        if (is_string($file) && $file !== '') {
            if (self::fileRefused($file, $backupRoots)) {
                return self::unusable('inside_data');
            }
            $raw = @file_get_contents($file);
            if (!is_string($raw)) {
                return self::unusable('unreadable');
            }
            $key = self::bytes($raw);
            if ($key === null) {
                return self::unusable('malformed_file');
            }

            return $sodium ? self::usable($key) : self::unusable('sodium');
        }

        return self::none();
    }

    /**
     * True when this path must not be used as APP_KEY_FILE: inside the data
     * directory or a directory a backup can contain (symlinks resolved).
     *
     * @param list<string> $roots
     */
    public static function fileRefused(string $path, array $roots): bool
    {
        $path = trim($path);
        if ($path === '') {
            return true;
        }
        $candidates = self::pathForms($path);
        foreach ($roots as $root) {
            foreach (self::pathForms($root) as $rootForm) {
                foreach ($candidates as $candidate) {
                    if (self::inside($candidate, $rootForm)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** The phrase shared by the log line and the save error (legacy secret_problem_phrase()). */
    public static function phrase(string $problem): string
    {
        return match ($problem) {
            'malformed' => 'APP_KEY is not a 32-byte key',
            'malformed_file' => 'APP_KEY_FILE is not a 32-byte key',
            'unreadable' => 'APP_KEY_FILE could not be read',
            'inside_data' => 'APP_KEY_FILE is inside the data directory or a backup path',
            'sodium' => 'sodium extension is missing',
            default => 'APP_KEY cannot be used',
        };
    }

    /** Sentence-case phrase (legacy secret_problem_label()). */
    public static function label(string $problem): string
    {
        $phrase = self::phrase($problem);

        return $problem === 'sodium' ? 'The ' . $phrase : $phrase;
    }

    /** @return list<string> */
    private static function pathForms(string $path): array
    {
        $path = str_replace('\\', '/', $path);
        $out = [$path];
        $real = realpath($path);
        if (is_string($real)) {
            $out[] = str_replace('\\', '/', $real);
        } else {
            $parent = realpath(dirname($path));
            if (is_string($parent)) {
                $out[] = rtrim(str_replace('\\', '/', $parent), '/') . '/' . basename($path);
            }
        }

        return array_values(array_unique($out));
    }

    private static function inside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($path === '' || $root === '') {
            return false;
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
