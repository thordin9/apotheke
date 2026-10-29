<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

/**
 * A response whose body is written chunk by chunk when it is sent, like
 * legacy's fwrite() to php://output: CodeIgniter keeps getBody() (empty
 * here) for after-filters and the CSP, and the chunks never sit in memory
 * together. Every FLUSH bytes the output buffer that Config/Events opens at
 * pre_system is passed on and the SAPI flushed, so that buffer stays small.
 */
final class StreamedResponse extends Response
{
    public const FLUSH = 65536;

    /** @var iterable<string> */
    private iterable $chunks = [];

    /**
     * A copy of $base (status, headers, cookies) that sends $chunks as its body.
     *
     * @param iterable<string> $chunks
     */
    public static function from(ResponseInterface $base, App $appConfig, iterable $chunks): self
    {
        $r = new self($appConfig);
        $r->setStatusCode($base->getStatusCode(), $base->getReasonPhrase());
        $r->setProtocolVersion($base->getProtocolVersion());
        foreach (array_keys($r->headers()) as $name) {
            $r->removeHeader($name);
        }
        foreach ($base->headers() as $name => $header) {
            foreach (is_array($header) ? $header : [$header] as $i => $h) {
                $i === 0 ? $r->setHeader($name, $h->getValue()) : $r->addHeader($name, $h->getValueLine());
            }
        }
        foreach ($base->getCookies() as $cookie) {
            $r->setCookie($cookie);
        }
        $r->setBody((string)$base->getBody());
        $r->chunks = $chunks;

        return $r;
    }

    public function sendBody()
    {
        echo $this->body;
        $pending = strlen((string)$this->body);
        foreach ($this->chunks as $chunk) {
            echo $chunk;
            $pending += strlen($chunk);
            if ($pending >= self::FLUSH) {
                self::flushOut();
                $pending = 0;
            }
        }
        self::flushOut();

        return $this;
    }

    private static function flushOut(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
