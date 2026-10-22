<?php

declare(strict_types=1);

namespace Apotheke\Migrations\Commands;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Migrations\MigrateConnectException;
use Apotheke\Migrations\PdoMigrationRunner;
use CodeIgniter\CLI\BaseCommand;
use RuntimeException;
use Throwable;

/** apotheke:migrate [--status]. Output matches bin/migrate.php. */
final class Migrate extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'apotheke:migrate';

    protected $description = 'Apply pending database migrations, or list them with --status.';

    protected $usage = 'apotheke:migrate [--status]';

    /**
     * @param array<array-key, string|null> $params
     * @return int
     */
    public function run(array $params)
    {
        $config = ApothekeConfig::fromEnvironment();
        $db = $config->database();
        $runner = new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations');
        apotheke_migration_cfg($config->values());
        $driver = (string)($db['driver'] ?? '');
        fwrite(STDOUT, PdoMigrationRunner::banner($db) . "\n");
        try {
            $pdo = $runner->connect($db);
            if (in_array('--status', $params, true) || in_array('--status', $_SERVER['argv'] ?? [], true)) {
                $applied = $runner->applied($pdo, $driver);
                foreach ($runner->available() as $version => $file) {
                    $name = substr(basename($file, '.php'), 5);
                    $state = isset($applied[$version]) ? 'applied ' . $applied[$version] : 'PENDING';
                    fwrite(STDOUT, sprintf("  %s  %-40s %s\n", $version, $name, $state));
                }
                $runner->close($pdo);

                return 0;
            }
            $done = $runner->migrate($pdo, $driver, [
                'data_dir' => $config->dataDir(),
                'seed' => (bool)($config->values()['seed_defaults'] ?? false),
                'log' => static function (string $message): void {
                    fwrite(STDOUT, '  ' . $message . "\n");
                },
            ]);
            $runner->close($pdo);
            fwrite(STDOUT, $done ? 'Done: ' . count($done) . " step(s).\n" : "Already up to date.\n");

            return 0;
        } catch (MigrateConnectException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        } catch (RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        } catch (Throwable) {
            fwrite(STDERR, "Migration failed.\n");

            return 1;
        }
    }
}
