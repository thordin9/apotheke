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

    /** At most 80 characters, with the bearer token removed. For a response body inside a log line. */
    public function snippet(string $body): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $this->redact($body)) ?? '');

        return mb_substr($s, 0, 80);
    }

    private function redact(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? '';
        $token = ($this->token)();
        if ($token !== '') {
            $message = str_replace($token, '[redacted]', $message);
        }

        return $message;
    }
}
