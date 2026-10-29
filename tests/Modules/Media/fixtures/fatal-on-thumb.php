<?php

declare(strict_types=1);

/*
 * MediaTest::testAFatalAfterTheOriginalIsSavedLeavesNoFiles: an upload under
 * the real CI4 bootstrap (exception handler, Services) whose thumbnail write
 * runs out of memory, after the original, its map entry and the full copy exist.
 *   php fatal-on-thumb.php <jpeg>      (DATA_DIR: a migrated data directory)
 */

use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\Storage;
use Apotheke\Media\Storage\ServedImageWriter;
use CodeIgniter\Boot;
use Config\Paths;
use Config\Services;

$ci4 = dirname(__DIR__, 4);
define('FCPATH', $ci4 . '/public/');
chdir(FCPATH);
require $ci4 . '/app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
define('ENVIRONMENT', (string)(getenv('CI_ENVIRONMENT') ?: 'production'));
Boot::bootConsole($paths);

final class FatalOnThumb implements Storage
{
    public function __construct(private readonly LocalStorage $inner, private readonly string $data)
    {
    }

    public function read(string $key): ?string
    {
        return $this->inner->read($key);
    }

    public function write(string $key, string $bytes): void
    {
        if (str_starts_with($key, 'thumbs/')) {
            $count = fn (string $glob): int => count(glob($this->data . '/' . $glob) ?: []);
            echo 'before-fatal originals=' . $count('originals/*.jpg') . ' map=' . $count('originals/map/*') . ' full=' . $count('images/*.jpg') . "\n";
            $hog = [];
            while (true) {
                $hog[] = str_repeat('x', 1 << 20);
            }
        }
        $this->inner->write($key, $bytes);
    }

    public function writeStream(string $key, mixed $stream): void
    {
        $this->inner->writeStream($key, $stream);
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function modifiedAt(string $key): ?int
    {
        return $this->inner->modifiedAt($key);
    }
}

$data = (string)getenv('DATA_DIR');
Services::injectMock('servedImages', new ServedImageWriter(new FatalOnThumb(new LocalStorage($data), $data)));
$jpeg = $argv[1];
Services::imageStore()->store($jpeg, (int)filesize($jpeg));
echo "stored\n";
