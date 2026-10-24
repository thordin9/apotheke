<?php

declare(strict_types=1);

namespace Apotheke\Core\Security;

use Apotheke\Core\Exceptions\UserError;
use Closure;
use Throwable;

/**
 * At-rest encryption for recoverable secrets (S-L5), the same envelope and
 * log lines as legacy src/secretbox.php, so both apps read one database:
 * "enc:v1:" + base64(nonce . sodium_crypto_secretbox(plain, nonce, key)).
 * A value without the prefix is legacy plaintext.
 *
 * The key is resolved on first use through $key (env and key file today; a
 * secret binding in the Cloudflare phase). Each line is logged once per
 * instance, which Config\Services builds per request. No statics, and key
 * material and secret values are never logged.
 */
final class SecretBox
{
    public const ENVELOPE = 'enc:v1:';

    public const NOTICE = 'apotheke: set APP_KEY to encrypt stored secrets';

    private ?SecretKey $resolved = null;

    /** @var array<string,true> */
    private array $logged = [];

    /**
     * @param Closure():SecretKey $key
     * @param Closure(string):void $log
     */
    public function __construct(
        private readonly Closure $key,
        private readonly bool $sodium,
        private readonly Closure $log,
    ) {
    }

    /**
     * @param array<string,mixed> $env
     * @param list<string> $backupRoots
     * @param (Closure(string):void)|null $log error_log() when null
     */
    public static function fromEnvironment(array $env, array $backupRoots, ?Closure $log = null): self
    {
        return new self(
            static fn (): SecretKey => SecretKey::fromEnvironment($env, $backupRoots),
            SecretKey::sodiumAvailable($env),
            $log ?? static function (string $message): void {
                error_log($message);
            },
        );
    }

    /** The current key, or null; logs why there is none (legacy secret_key()). */
    public function key(): ?string
    {
        $state = $this->state();
        if ($state->problem !== null) {
            $this->logProblem($state->problem);

            return null;
        }
        if ($state->key === null) {
            $this->logOnce(self::NOTICE);

            return null;
        }

        return $state->key;
    }

    /** Why a configured key cannot be used, or null (usable or none configured). */
    public function problem(): ?string
    {
        return $this->state()->problem;
    }

    /**
     * Seal a secret for storage (legacy secret_seal()). '' stays ''. Without a
     * key the value stays plaintext, as on legacy. A configured key that
     * cannot be used refuses the write with a 422 that names the cause.
     */
    public function seal(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $state = $this->state();
        if ($state->problem !== null) {
            $this->logProblem($state->problem);

            throw new UserError(SecretKey::label($state->problem) . '. The secret was not saved.', 422);
        }
        if (!$this->sodium) {
            $this->logOnce('apotheke: ext-sodium is missing; stored secrets stay unencrypted.');
            $this->logOnce(self::NOTICE);

            return $plain;
        }
        if ($state->key === null) {
            $this->logOnce(self::NOTICE);

            return $plain;
        }

        return self::sealWith($plain, $state->key);
    }

    /** Plaintext, or '' when an enc:v1: value cannot be opened (legacy secret_open()). Never throws. */
    public function open(string $name, string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, self::ENVELOPE)) {
            return $stored;
        }

        return $this->openWith($name, $stored, $this->key(), true) ?? '';
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::ENVELOPE);
    }

    public static function sealWith(string $plain, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::ENVELOPE . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    /** Plaintext, or null when an enc:v1: value does not open with $key. */
    public function openWith(string $name, string $stored, ?string $key, bool $log): ?string
    {
        if ($stored === '') {
            return '';
        }
        if (!self::isSealed($stored)) {
            return $stored;
        }
        $fail = function () use ($name, $log): ?string {
            if ($log) {
                $this->logOnce('apotheke: setting ' . $name . ' could not be decrypted and is treated as unset.');
            }

            return null;
        };
        if ($key === null || !$this->sodium) {
            return $fail();
        }
        try {
            $raw = base64_decode(substr($stored, strlen(self::ENVELOPE)), true);
            $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (!is_string($raw) || strlen($raw) < $nonceLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
                return $fail();
            }
            $plain = sodium_crypto_secretbox_open(substr($raw, $nonceLen), substr($raw, 0, $nonceLen), $key);

            return is_string($plain) ? $plain : $fail();
        } catch (Throwable) {
            return $fail();
        }
    }

    private function state(): SecretKey
    {
        return $this->resolved ??= ($this->key)();
    }

    private function logProblem(string $problem): void
    {
        $this->logOnce('apotheke: ' . SecretKey::phrase($problem) . '; stored secrets will not be saved.');
    }

    private function logOnce(string $message): void
    {
        if (isset($this->logged[$message])) {
            return;
        }
        $this->logged[$message] = true;
        ($this->log)($message);
    }
}
