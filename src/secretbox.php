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

/**
 * True when libsodium can seal and open. APOTHEKE_SODIUM=0 is a test seam: the
 * extension is built into this PHP build and cannot be unloaded.
 */
function secret_sodium_available(): bool
{
    if (getenv('APOTHEKE_SODIUM') === '0') return false;
    return function_exists('sodium_crypto_secretbox')
        && function_exists('sodium_crypto_secretbox_open')
        && function_exists('sodium_crypto_secretbox_keygen');
}

/** 32 raw key bytes, or null when $raw is not base64 of that length. Does not check sodium. */
function secret_key_bytes(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    if (str_starts_with($raw, 'base64:')) $raw = trim(substr($raw, 7));
    $bin = base64_decode($raw, true);
    $n = defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES') ? SODIUM_CRYPTO_SECRETBOX_KEYBYTES : 32;
    if (!is_string($bin) || strlen($bin) !== $n) return null;
    return $bin;
}

/** Base64 of 32 bytes, with an optional "base64:" prefix. Null when the text is not that key. */
function secret_key_decode(string $raw): ?string
{
    $key = secret_key_bytes($raw);
    if ($key === null || !secret_sodium_available()) return null;
    return $key;
}

/**
 * Why a configured key cannot be used.
 * malformed | malformed_file | unreadable | inside_data | sodium, or null when
 * there is a usable key or no key is configured.
 * @return array{key:?string,problem:?string}
 */
function secret_key_state(): array
{
    $env = getenv('APP_KEY');
    if (is_string($env) && $env !== '') {
        $key = secret_key_bytes($env);
        if ($key === null) return ['key' => null, 'problem' => 'malformed'];
        if (!secret_sodium_available()) return ['key' => null, 'problem' => 'sodium'];
        return ['key' => $key, 'problem' => null];
    }
    $file = getenv('APP_KEY_FILE');
    if (is_string($file) && $file !== '') {
        if (secret_key_file_refused($file)) return ['key' => null, 'problem' => 'inside_data'];
        $raw = @file_get_contents($file);
        if (!is_string($raw)) return ['key' => null, 'problem' => 'unreadable'];
        $key = secret_key_bytes($raw);
        if ($key === null) return ['key' => null, 'problem' => 'malformed_file'];
        if (!secret_sodium_available()) return ['key' => null, 'problem' => 'sodium'];
        return ['key' => $key, 'problem' => null];
    }
    return ['key' => null, 'problem' => null];
}

/** Cause phrase shared by the log line, the Control Panel warning and the save error. */
function secret_problem_phrase(string $problem): string
{
    return match ($problem) {
        'malformed' => 'APP_KEY is not a 32-byte key',
        'malformed_file' => 'APP_KEY_FILE is not a 32-byte key',
        'unreadable' => 'APP_KEY_FILE could not be read',
        'inside_data' => 'APP_KEY_FILE is inside the data directory or a backup path',
        'sodium' => 'sodium extension is missing',
    };
}

function secret_problem_label(string $problem): string
{
    $phrase = secret_problem_phrase($problem);
    return $problem === 'sodium' ? 'The ' . $phrase : $phrase;
}

function secret_log_problem(string $problem): void
{
    secret_log_once('apotheke: ' . secret_problem_phrase($problem) . '; stored secrets will not be saved.');
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
 * The current 32-byte key, or null when none is usable.
 * A non-empty APP_KEY wins over APP_KEY_FILE, including when APP_KEY does not decode.
 * A configured but unusable key returns null; secret_seal() then refuses the write.
 */
function secret_key(): ?string
{
    $state = secret_key_state();
    if ($state['problem'] !== null) {
        secret_log_problem($state['problem']);
        return null;
    }
    if ($state['key'] === null) {
        secret_log_once('apotheke: set APP_KEY to encrypt stored secrets');
        return null;
    }
    return $state['key'];
}

function secret_storage_warning_html(): string
{
    $state = secret_key_state();
    if ($state['problem'] !== null) {
        secret_log_problem($state['problem']);
        $msg = secret_problem_label($state['problem']) . '. Stored secrets cannot be saved.';
        return '<p class="set-warn" role="status" id="secret-key-warn">' . h($msg) . '</p>';
    }
    if ($state['key'] !== null) return '';
    secret_log_once('apotheke: set APP_KEY to encrypt stored secrets');
    return '<p class="set-warn" role="status" id="secret-key-warn">Set APP_KEY to encrypt stored secrets.</p>';
}

function secret_seal(string $plain): string
{
    if ($plain === '') return '';
    $state = secret_key_state();
    if ($state['problem'] !== null) {
        secret_log_problem($state['problem']);
        throw new UserError(secret_problem_label($state['problem']) . '. The secret was not saved.');
    }
    if (!secret_sodium_available()) {
        secret_log_once('apotheke: ext-sodium is missing; stored secrets stay unencrypted.');
        secret_log_once('apotheke: set APP_KEY to encrypt stored secrets');
        return $plain;
    }
    if ($state['key'] === null) {
        secret_log_once('apotheke: set APP_KEY to encrypt stored secrets');
        return $plain;
    }
    return secret_seal_with($plain, $state['key']);
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
    if ($key === null || !secret_sodium_available()) return $fail();
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
