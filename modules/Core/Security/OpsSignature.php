<?php

declare(strict_types=1);

namespace Apotheke\Core\Security;

/**
 * The signature on an operator request from the hosting side to its own container (operator console C2). The key is
 * derived from the installation's APP_KEY, which the hosting side already sets for the container, so no other secret
 * is passed:
 *
 *   key       = HMAC-SHA256(APP_KEY bytes, "apotheke-ops-v1")
 *   signed    = METHOD "\n" PATH "\n" t "\n" hex(SHA-256(body))
 *   header    X-Apotheke-Ops-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(key, signed)>
 *
 * A signature is accepted for WINDOW seconds either side of the container's clock. edge/src/ops-signature.ts makes the
 * same header; both sides check one fixed test vector.
 */
final class OpsSignature
{
    public const HEADER = 'X-Apotheke-Ops-Signature';

    public const WINDOW = 300;

    private const CONTEXT = 'apotheke-ops-v1';

    private function __construct(#[\SensitiveParameter] private readonly string $key)
    {
    }

    /** From APP_KEY as the container gets it (`base64:` and 32 bytes); null when it is missing or malformed. */
    public static function fromAppKey(#[\SensitiveParameter] ?string $appKey): ?self
    {
        $bytes = is_string($appKey) ? SecretKey::bytes($appKey) : null;

        return $bytes === null ? null : new self(hash_hmac('sha256', self::CONTEXT, $bytes, true));
    }

    public function header(string $method, string $path, string $body, int $t): string
    {
        return 't=' . $t . ',v1=' . $this->mac($method, $path, $body, $t);
    }

    /** Whether $header signs exactly this request, made within WINDOW seconds of $now. */
    public function verify(?string $header, string $method, string $path, string $body, int $now): bool
    {
        if (!is_string($header) || preg_match('/^t=(\d{1,12}),v1=([0-9a-f]{64})$/D', trim($header), $m) !== 1) {
            return false;
        }
        $t = (int)$m[1];
        if (abs($now - $t) > self::WINDOW) {
            return false;
        }

        return hash_equals($this->mac($method, $path, $body, $t), $m[2]);
    }

    private function mac(string $method, string $path, string $body, int $t): string
    {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $path . "\n" . $t . "\n" . hash('sha256', $body), $this->key);
    }
}
