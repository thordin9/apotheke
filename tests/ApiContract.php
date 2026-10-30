<?php

declare(strict_types=1);

use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Assert;

/**
 * The API contract harness (API plan §7): a test client's response must match
 * docs/api/openapi.yaml for its operation (status, content type,
 * headers and body schema). Dev only: league/openapi-psr7-validator over
 * nyholm/psr7.
 */
final class ApiContract
{
    public const SPEC = __DIR__ . '/../docs/api/openapi.yaml';

    private static ?ResponseValidator $validator = null;

    /** @param array{status:int,body:string,raw:list<string>} $response a Client answer */
    public static function assertMatches(string $method, string $path, array $response): void
    {
        self::$validator ??= (new ValidatorBuilder())->fromJsonFile(self::SPEC)->getResponseValidator();
        try {
            self::$validator->validate(new OperationAddress($path, strtolower($method)), self::psr($response));
        } catch (ValidationFailed $e) {
            $why = $e->getMessage();
            for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
                $why .= ' / ' . $p->getMessage();
            }
            Assert::fail(strtoupper($method) . ' ' . $path . ' ' . $response['status'] . ' does not match the OpenAPI document: ' . $why . "\n" . $response['body']);
        }
        Assert::assertTrue(true);
    }

    /** @param array{status:int,body:string,raw:list<string>} $response */
    private static function psr(array $response): Response
    {
        $headers = [];
        foreach ($response['raw'] as $line) {
            if (!str_contains($line, ':') || str_starts_with($line, 'HTTP/')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $headers[$name][] = $value;
        }

        return new Response($response['status'], $headers, $response['body']);
    }
}
