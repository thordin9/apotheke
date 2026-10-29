<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Cli\WiredCommands;
use Apotheke\Settings\Commands\SettingsCommand;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Psr\Log\LoggerInterface;

final class WiredCommandsTest extends CIUnitTestCase
{
    public function testAWiredCommandIsBuiltByItsFactory(): void
    {
        $built = 0;
        $commands = new WiredCommands(Services::logger(), [
            SettingsCommand::class => static function (LoggerInterface $logger, Commands $c) use (&$built): BaseCommand {
                $built++;

                return new class ($logger, $c) extends BaseCommand {
                    protected $name = 'apotheke:settings';

                    public function run(array $params)
                    {
                        return 7;
                    }
                };
            },
        ]);
        $this->assertArrayHasKey('apotheke:settings', $commands->getCommands());
        $this->assertSame(7, $commands->run('apotheke:settings', []));
        $this->assertSame(1, $built);
    }

    public function testServicesWiresTheSettingsCommand(): void
    {
        $this->assertInstanceOf(WiredCommands::class, Services::commands(false));
    }
}
