<?php

declare(strict_types=1);

namespace App\Commands;

use Apotheke\Core\Http\CompiledRouteCollection;
use CodeIgniter\CLI\BaseCommand;
use Config\Services;

/** apotheke:routes [--check]: writes app/Config/RoutesCompiled.php, or with --check exits 1 when it is out of date. */
final class CompileRoutes extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'apotheke:routes';

    protected $description = 'Compile app/Config/Routes.php into app/Config/RoutesCompiled.php (--check: only compare).';

    protected $usage = 'apotheke:routes [--check]';

    /**
     * @param array<array-key, string|null> $params
     * @return int
     */
    public function run(array $params)
    {
        $routes = Services::routes(false);
        if (!$routes instanceof CompiledRouteCollection) {
            fwrite(STDERR, "The routes service is not the compiled route collection.\n");

            return 1;
        }
        $source = $routes->compile();
        $file = CompiledRouteCollection::compiledFile();
        $check = in_array('--check', $params, true) || in_array('--check', $_SERVER['argv'] ?? [], true);
        if ($check) {
            if (@file_get_contents($file) === $source) {
                fwrite(STDOUT, "app/Config/RoutesCompiled.php is up to date.\n");

                return 0;
            }
            fwrite(STDERR, "app/Config/RoutesCompiled.php is out of date: run php spark apotheke:routes\n");

            return 1;
        }
        if (file_put_contents($file, $source) === false) {
            fwrite(STDERR, "Could not write app/Config/RoutesCompiled.php\n");

            return 1;
        }
        fwrite(STDOUT, "Wrote app/Config/RoutesCompiled.php\n");

        return 0;
    }
}
