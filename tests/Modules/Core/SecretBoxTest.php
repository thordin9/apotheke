<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Security\SecretBox;
use Apotheke\Core\Security\SecretKey;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * S-L5 key resolution and the enc:v1: envelope (legacy secretbox.php).
 * Placeholder keys only.
 */
final class SecretBoxTest extends CIUnitTestCase
{
    private const RAW = 'placeholder-key-a-32-bytes-long!';

    private string $dir = '';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/apotheke-secretbox-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/data', 0775, true);
        mkdir($this->dir . '/outside', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (['data/app.key', 'outside/app.key', 'outside/bad.key'] as $f) {
            @chmod($this->dir . '/' . $f, 0644);
            @unlink($this->dir . '/' . $f);
        }
        @rmdir($this->dir . '/data');
        @rmdir($this->dir . '/outside');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testKeyResolutionMatchesLegacy(): void
    {
        $roots = [$this->dir . '/data'];
        $raw = self::RAW;
        $this->assertSame([null, null], $this->state([], $roots));
        $this->assertSame([$raw, null], $this->state(['APP_KEY' => $this->key()], $roots));
        $this->assertSame([$raw, null], $this->state(['APP_KEY' => ' base64:' . $this->key() . "\n"], $roots));
        $this->assertSame([null, 'malformed'], $this->state(['APP_KEY' => 'not-a-key-placeholder'], $roots));
        $this->assertSame([null, 'malformed'], $this->state(['APP_KEY' => base64_encode('short')], $roots));
        $this->assertSame([null, 'sodium'], $this->state(['APP_KEY' => $this->key(), 'APOTHEKE_SODIUM' => '0'], $roots));

        file_put_contents($this->dir . '/outside/app.key', $this->key() . "\n");
        $this->assertSame([$raw, null], $this->state(['APP_KEY_FILE' => $this->dir . '/outside/app.key'], $roots));
        // A non-empty APP_KEY wins over the file, even when it does not decode.
        $this->assertSame([null, 'malformed'], $this->state(['APP_KEY' => 'x', 'APP_KEY_FILE' => $this->dir . '/outside/app.key'], $roots));
        file_put_contents($this->dir . '/outside/bad.key', "placeholder\n");
        $this->assertSame([null, 'malformed_file'], $this->state(['APP_KEY_FILE' => $this->dir . '/outside/bad.key'], $roots));
        $this->assertSame([null, 'unreadable'], $this->state(['APP_KEY_FILE' => $this->dir . '/outside/missing.key'], $roots));
        file_put_contents($this->dir . '/data/app.key', $this->key() . "\n");
        $this->assertSame([null, 'inside_data'], $this->state(['APP_KEY_FILE' => $this->dir . '/data/app.key'], $roots));
        $this->assertSame([null, 'inside_data'], $this->state(['APP_KEY_FILE' => $this->dir . '/outside/../data/app.key'], $roots));
        $this->assertSame([null, 'inside_data'], $this->state(['APP_KEY_FILE' => $this->dir . '/data'], $roots));
    }

    /** #89 N-1: only exactly 32 decoded bytes are a key, in APP_KEY and in APP_KEY_FILE. */
    public function testAKeyOfAnyOtherLengthIsMalformed(): void
    {
        $roots = [$this->dir . '/data'];
        foreach ([16, 24, 31, 33] as $n) {
            $value = base64_encode(substr(str_repeat(self::RAW, 2), 0, $n));
            $this->assertSame([null, 'malformed'], $this->state(['APP_KEY' => $value], $roots), "$n bytes");
            file_put_contents($this->dir . '/outside/bad.key', $value . "\n");
            $this->assertSame([null, 'malformed_file'], $this->state(['APP_KEY_FILE' => $this->dir . '/outside/bad.key'], $roots), "$n bytes in a file");
        }
    }

    /** #89 N-1: a usable key file without sodium is refused like APP_KEY without sodium. */
    public function testAKeyFileWithoutSodiumIsRefused(): void
    {
        file_put_contents($this->dir . '/outside/app.key', $this->key() . "\n");
        $env = ['APP_KEY_FILE' => $this->dir . '/outside/app.key', 'APOTHEKE_SODIUM' => '0'];
        $this->assertSame([null, 'sodium'], $this->state($env, [$this->dir . '/data']));
        $box = $this->box($env);
        try {
            $box->seal('placeholder-secret');
            $this->fail('sealed without sodium');
        } catch (UserError $e) {
            $this->assertSame('The sodium extension is missing. The secret was not saved.', $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }
        $this->assertSame(['apotheke: sodium extension is missing; stored secrets will not be saved.'], $this->logged);
    }

    public function testSealAndOpen(): void
    {
        $box = $this->box(['APP_KEY' => $this->key()]);
        $sealed = $box->seal('placeholder-secret');
        $this->assertStringStartsWith(SecretBox::ENVELOPE, $sealed);
        $this->assertStringNotContainsString('placeholder-secret', $sealed);
        $this->assertNotSame($sealed, $box->seal('placeholder-secret'), 'a fresh nonce each time');
        $this->assertSame('placeholder-secret', $box->open('autofill_token', $sealed));
        $this->assertSame('', $box->seal(''));
        $this->assertSame('plain-legacy-value', $box->open('autofill_token', 'plain-legacy-value'));
        $this->assertSame('', $box->open('autofill_token', SecretBox::ENVELOPE . 'garbage'));
        $this->assertSame('', $box->open('autofill_token', substr($sealed, 0, -2) . 'AA'));
        $this->assertSame(['apotheke: setting autofill_token could not be decrypted and is treated as unset.'], $this->logged, 'logged once');
    }

    public function testWithoutAKeySecretsStayPlaintextAndTheNoticeIsLoggedOnce(): void
    {
        $box = $this->box([]);
        $this->assertNull($box->key());
        $this->assertSame('placeholder-secret', $box->seal('placeholder-secret'));
        $this->assertSame('', $box->open('autofill_token', SecretBox::ENVELOPE . 'AAAA'));
        $this->assertSame([SecretBox::NOTICE, 'apotheke: setting autofill_token could not be decrypted and is treated as unset.'], $this->logged);

        $this->logged = [];
        $box = $this->box(['APOTHEKE_SODIUM' => '0']);
        $this->assertSame('placeholder-secret', $box->seal('placeholder-secret'));
        $this->assertSame(['apotheke: ext-sodium is missing; stored secrets stay unencrypted.', SecretBox::NOTICE], $this->logged);
    }

    public function testAnUnusableKeyRefusesTheSealAndNeverLogsTheKey(): void
    {
        $cases = [
            'malformed' => [['APP_KEY' => 'malformed-placeholder'], 'APP_KEY is not a 32-byte key', 'APP_KEY is not a 32-byte key'],
            'sodium' => [['APP_KEY' => $this->key(), 'APOTHEKE_SODIUM' => '0'], 'The sodium extension is missing', 'sodium extension is missing'],
            'unreadable' => [['APP_KEY_FILE' => $this->dir . '/outside/missing.key'], 'APP_KEY_FILE could not be read', 'APP_KEY_FILE could not be read'],
        ];
        foreach ($cases as $name => [$env, $label, $phrase]) {
            $this->logged = [];
            $box = $this->box($env);
            try {
                $box->seal('placeholder-secret');
                $this->fail($name . ': sealed with an unusable key');
            } catch (UserError $e) {
                $this->assertSame($label . '. The secret was not saved.', $e->getMessage(), $name);
                $this->assertSame(422, $e->getCode(), $name);
            }
            $this->assertNull($box->key(), $name);
            $this->assertSame(['apotheke: ' . $phrase . '; stored secrets will not be saved.'], $this->logged, $name);
            $this->assertStringNotContainsString($this->key(), implode("\n", $this->logged));
            $this->assertStringNotContainsString('malformed-placeholder', implode("\n", $this->logged));
        }
    }

    public function testTheKeyIsResolvedOnFirstUseOnly(): void
    {
        $calls = 0;
        $box = new SecretBox(static function () use (&$calls): SecretKey {
            $calls++;

            return SecretKey::none();
        }, true, static function (string $message): void {
        });
        $this->assertSame(0, $calls);
        $box->seal('a');
        $box->seal('b');
        $box->key();
        $this->assertSame(1, $calls);
    }

    /** A placeholder APP_KEY: base64 of 32 readable bytes. */
    private function key(): string
    {
        return base64_encode(self::RAW);
    }

    /**
     * @param array<string,string> $env
     * @param list<string> $roots
     * @return array{?string,?string}
     */
    private function state(array $env, array $roots): array
    {
        $key = SecretKey::fromEnvironment($env, $roots);

        return [$key->key, $key->problem];
    }

    /** N-921: the key and the plain value never reach a stack trace through the arguments. */
    public function testTheKeyAndThePlainValueAreSensitiveParameters(): void
    {
        $sensitive = static function (string $method): array {
            $names = [];
            foreach ((new \ReflectionMethod(SecretBox::class, $method))->getParameters() as $param) {
                if ($param->getAttributes(\SensitiveParameter::class) !== []) {
                    $names[] = $param->getName();
                }
            }

            return $names;
        };
        $this->assertSame(['plain'], $sensitive('seal'));
        $this->assertSame(['plain', 'key'], $sensitive('sealWith'));
        $this->assertSame(['key'], $sensitive('openWith'));
    }

    /** @param array<string,string> $env */
    private function box(array $env): SecretBox
    {
        return SecretBox::fromEnvironment($env, [$this->dir . '/data'], function (string $message): void {
            $this->logged[] = $message;
        });
    }
}
