<?php
declare(strict_types=1);

/*
 * At-rest encryption for recoverable secrets (not password hashes).
 *
 * The envelope and the key rules are specified in docs/configuration.md so another
 * port can read the same database. This file does not log key material or secret values.
 */

const SECRET_ENVELOPE = 'enc:v1:';

function secret_log_once(string $message): void
{
    static $seen = [];
    if (isset($seen[$message])) return;
    $seen[$message] = true;
    error_log($message);
}

/** Base64 of 32 bytes, with an optional "base64:" prefix. Null when the text is not that key. */
function secret_key_decode(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    if (str_starts_with($raw, 'base64:')) $raw = trim(substr($raw, 7));
    if (!function_exists('sodium_crypto_secretbox_keygen')) return null;
    $bin = base64_decode($raw, true);
    if (!is_string($bin) || strlen($bin) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return null;
    return $bin;
}

/** Directories whose files a backup zip can contain, plus the data directory itself. */
function secret_backup_roots(): array
{
    $roots = [];
    if (defined('DATA_DIR')) {
        $roots[] = DATA_DIR;
        $roots[] = DATA_DIR . '/images';
        $roots[] = DATA_DIR . '/thumbs';
        $roots[] = DATA_DIR . '/files';
        $roots[] = DATA_DIR . '/originals';
    }
    if (defined('ORIGINALS_DIR')) $roots[] = ORIGINALS_DIR;
    if (defined('IMG_DIR')) $roots[] = IMG_DIR;
    if (defined('THUMB_DIR')) $roots[] = THUMB_DIR;
    return $roots;
}

function secret_path_forms(string $path): array
{
    $path = str_replace('\\', '/', $path);
    $out = [$path];
    $real = realpath($path);
    if (is_string($real)) {
        $out[] = str_replace('\\', '/', $real);
    } else {
        $parent = realpath(dirname($path));
        if (is_string($parent)) $out[] = rtrim(str_replace('\\', '/', $parent), '/') . '/' . basename($path);
    }
    return array_values(array_unique($out));
}

function secret_path_inside(string $path, string $root): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if ($path === '' || $root === '') return false;
    return $path === $root || str_starts_with($path, $root . '/');
}

/** True when this path must not be used as APP_KEY_FILE. */
function secret_key_file_refused(string $path): bool
{
    $path = trim($path);
    if ($path === '') return true;
    $candidates = secret_path_forms($path);
    foreach (secret_backup_roots() as $root) {
        foreach (secret_path_forms($root) as $rootForm) {
            foreach ($candidates as $cand) {
                if (secret_path_inside($cand, $rootForm)) return true;
            }
        }
    }
    return false;
}

/**
 * The current 32-byte key, or null when secrets stay as stored.
 * A non-empty APP_KEY wins over APP_KEY_FILE, including when APP_KEY does not decode.
 */
function secret_key(): ?string
{
    $env = getenv('APP_KEY');
    if (is_string($env) && $env !== '') {
        $key = secret_key_decode($env);
        if ($key === null) secret_log_once('apotheke: APP_KEY is not a 32-byte key; stored secrets stay unencrypted.');
        return $key;
    }
    $file = getenv('APP_KEY_FILE');
    if (is_string($file) && $file !== '') {
        if (secret_key_file_refused($file)) {
            secret_log_once('apotheke: APP_KEY_FILE is inside the data directory or a backup path and was not used.');
            secret_log_once('apotheke: set APP_KEY to encrypt stored secrets');
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            secret_log_once('apotheke: APP_KEY_FILE could not be read; stored secrets stay unencrypted.');
            secret_log_once('apotheke: set APP_KEY to encrypt stored secrets');
            return null;
        }
        $key = secret_key_decode($raw);
        if ($key === null) {
            secret_log_once('apotheke: APP_KEY_FILE is not a 32-byte key; stored secrets stay unencrypted.');
            secret_log_once('apotheke: set APP_KEY to encrypt stored secrets');
            return null;
        }
        return $key;
    }
    secret_log_once('apotheke: set APP_KEY to encrypt stored secrets');
    return null;
}

function secret_storage_warning_html(): string
{
    if (secret_key() !== null) return '';
    return '<p class="set-warn" role="status" id="secret-key-warn">Set APP_KEY to encrypt stored secrets.</p>';
}

function secret_seal(string $plain): string
{
    if ($plain === '') return '';
    if (!function_exists('sodium_crypto_secretbox')) {
        secret_log_once('apotheke: ext-sodium is missing; stored secrets stay unencrypted.');
        secret_log_once('apotheke: set APP_KEY to encrypt stored secrets');
        return $plain;
    }
    $key = secret_key();
    if ($key === null) return $plain;
    return secret_seal_with($plain, $key);
}

function secret_seal_with(string $plain, string $key): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return SECRET_ENVELOPE . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
}

/**
 * Plaintext, or null when an enc:v1: value cannot be opened.
 * A value without the prefix is legacy plaintext and is returned unchanged.
 */
function secret_open_with(string $name, string $stored, ?string $key, bool $log): ?string
{
    if ($stored === '') return '';
    if (!str_starts_with($stored, SECRET_ENVELOPE)) return $stored;
    $fail = function () use ($name, $log): null {
        if ($log) secret_log_once('apotheke: setting ' . $name . ' could not be decrypted and is treated as unset.');
        return null;
    };
    if ($key === null || !function_exists('sodium_crypto_secretbox_open')) return $fail();
    try {
        $raw = base64_decode(substr($stored, strlen(SECRET_ENVELOPE)), true);
        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (!is_string($raw) || strlen($raw) < $nonceLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES) return $fail();
        $plain = sodium_crypto_secretbox_open(substr($raw, $nonceLen), substr($raw, 0, $nonceLen), $key);
        if (!is_string($plain)) return $fail();
        return $plain;
    } catch (Throwable) {
        return $fail();
    }
}

/** Decrypted value, or '' when the envelope cannot be opened. Never throws. */
function secret_open(string $name, string $stored): string
{
    return secret_open_with($name, $stored, secret_key(), true) ?? '';
}
