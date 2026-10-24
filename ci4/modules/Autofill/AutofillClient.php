<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use Closure;

/**
 * One HTTP request to the autofill service. curl, 3 s connect timeout,
 * no redirects, http and https only, response cap 256 KiB. Never throws.
 * $transport replaces curl in tests; production passes null.
 */
final class AutofillClient
{
    public const MAX_BODY = 262144;

    public const CONNECT_TIMEOUT = 3;

    /**
     * @param Closure():int $now
     * @param null|(Closure(string,string,?string,string,int,string):array{status:int,body:string,error:?string,retry_after:?int,content_type:string}) $transport
     */
    public function __construct(private readonly Closure $now, private readonly ?Closure $transport = null)
    {
    }

    /**
     * @return array{status:int,body:string,error:?string,retry_after:?int,content_type:string}
     */
    public function request(string $method, string $url, ?string $body, string $token, int $timeout, string $contentType = ''): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $body, $token, $timeout, $contentType);
        }
        $empty = static fn (?string $err, int $status = 0, string $respType = ''): array => [
            'status' => $status, 'body' => '', 'error' => $err, 'retry_after' => null, 'content_type' => $respType,
        ];
        if (!function_exists('curl_init')) {
            return $empty('unavailable');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return $empty('unavailable');
        }
        $resp = '';
        $tooLarge = false;
        $retryAfter = null;
        $respType = '';
        $headers = ['Accept: application/json', 'Expect:'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($body !== null && $contentType !== '') {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        $classifier = new ResponseClassifier($this->now);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, max(1, $timeout)),
            CURLOPT_TIMEOUT => max(1, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter, &$respType, $classifier): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = $classifier->retryAfter(substr($line, 12));
                }
                if (stripos($line, 'content-type:') === 0) {
                    $respType = trim(substr($line, 13));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$resp, &$tooLarge): int {
                if (strlen($resp) + strlen($chunk) > self::MAX_BODY) {
                    $tooLarge = true;

                    return 0;
                }
                $resp .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $connected = (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
        curl_close($ch);
        if ($tooLarge) {
            return $empty('too_large', $status, $respType);
        }
        if ($ok === false || $errno !== 0) {
            $err = $errno === CURLE_OPERATION_TIMEDOUT && $connected ? 'timeout' : 'unreachable';

            return $empty($err);
        }

        return ['status' => $status, 'body' => $resp, 'error' => null, 'retry_after' => $retryAfter, 'content_type' => $respType];
    }
}
