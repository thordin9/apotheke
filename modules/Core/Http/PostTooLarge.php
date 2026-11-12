<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * A POST whose declared length is past post_max_size is the body PHP dropped. A smaller
 * body that fills no $_POST (JSON, text) is not too large; the route's own checks answer it.
 */
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
    public function triggered(string $method, array $post, array $files, int $length, ?int $limit = null): bool
    {
        $limit ??= self::limit();

        return strtoupper($method) === 'POST' && $post === [] && $files === [] && $limit > 0 && $length > $limit;
    }

    /** post_max_size in bytes; 0 or less is no limit. */
    public static function limit(): int
    {
        return ini_parse_quantity((string)ini_get('post_max_size'));
    }

    public function jsonBody(): string
    {
        $json = json_encode(['ok' => false, 'error' => $this->message()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{"ok":false,"error":"Upload too large."}';
    }
}
