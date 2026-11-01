<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Controllers\ApiController;
use cebe\openapi\Reader;
use CodeIgniter\Router\Router;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/** The OpenAPI document is valid and lists exactly the /api/v1 routes (API plan §3, §7). */
final class OpenApiTest extends CIUnitTestCase
{
    public function testTheDocumentIsValidOpenApi(): void
    {
        $spec = Reader::readFromJsonFile((string)realpath(ApiController::SPEC));
        $this->assertTrue($spec->validate(), implode("\n", $spec->getErrors()));
        $this->assertStringStartsWith('3.0.', (string)$spec->openapi);
    }

    public function testEveryRouteIsDocumentedAndEveryOperationRouted(): void
    {
        $collection = Services::routes();
        $collection->loadRoutes();
        $routed = [];
        foreach (array_values(array_diff(Router::HTTP_METHODS, ['CLI'])) as $verb) {
            foreach (array_keys($collection->getRoutes($verb)) as $route) {
                $route = (string)$route;
                // POST /consent is outside /api but documented too: the pages' no-JS consent form.
                if ((str_starts_with($route, 'api/') && $route !== 'api/(.*)') || $route === 'consent') {
                    $routed[] = strtolower($verb) . ' /' . $route;
                }
            }
        }
        $documented = [];
        $doc = json_decode((string)file_get_contents(ApiController::SPEC), true, 64, JSON_THROW_ON_ERROR);
        foreach ($doc['paths'] as $path => $operations) {
            foreach ($operations as $method => $op) {
                // A path parameter is an integer id ([0-9]+), or a string the controller checks itself ([^/]+).
                $types = [];
                foreach ($op['parameters'] ?? [] as $param) {
                    $param = isset($param['$ref']) ? $doc['components']['parameters'][substr($param['$ref'], strlen('#/components/parameters/'))] : $param;
                    $types[$param['name']] = $param['schema']['type'] ?? 'integer';
                }
                $documented[] = $method . ' ' . preg_replace_callback('/\{([a-z_]+)\}/', static fn (array $m): string => ($types[$m[1]] ?? 'integer') === 'string' ? '([^/]+)' : '([0-9]+)', $path);
            }
        }
        sort($routed);
        sort($documented);
        $this->assertSame($documented, $routed);
    }
}
