<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use Closure;

/**
 * Warning lines for the autofill client. The bearer token is removed
 * before the line is written. Nothing here is remembered between calls.
 */
final class LogRedactor
{
    /**
     * @param Closure(string):void $sink
     * @param Closure():string $token the current service token (may change after this object is built)
     */
    public function __construct(private readonly Closure $sink, private readonly Closure $token)
    {
    }

    public function warning(string $message): void
    {
        ($this->sink)('apotheke autofill: ' . $this->redact($message));
    }

    /**
     * At most 80 characters, with the bearer token removed. For a response body inside a log line.
     * $also is a token from this request (the one typed into the form) when it is not the stored token.
     */
    public function snippet(string $body, string $also = ''): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $this->redact($body, $also)) ?? '');

        return mb_substr($s, 0, 80);
    }

    private function redact(string $message, string $also = ''): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? '';
        foreach ([$also, ($this->token)()] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return $message;
    }
}
