<?php

declare(strict_types=1);

namespace Apotheke\Core\Libraries;

final class Responder
{
    /** @param array<string,mixed> $data */
    public function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{}';
    }
}
