<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/** An empty POST that still has a Content-Length is the body PHP dropped past post_max_size. */
final class PostTooLarge
{
    public function message(): string
    {
        return 'Upload too large (max ' . ini_get('post_max_size') . ').';
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     */
    public function triggered(string $method, array $post, array $files, int $length): bool
    {
        return strtoupper($method) === 'POST' && $post === [] && $files === [] && $length > 0;
    }

    public function jsonBody(): string
    {
        $json = json_encode(['ok' => false, 'error' => $this->message()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{"ok":false,"error":"Upload too large."}';
    }
}
