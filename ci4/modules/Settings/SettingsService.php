<?php

declare(strict_types=1);

namespace Apotheke\Settings;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\ActorGate;
use Apotheke\Core\Security\Nobody;
use Apotheke\Core\Security\SecretBox;
use Closure;
use LogicException;
use Throwable;

/**
 * Control Panel settings: read, save, reset, export and import, with the
 * rules of legacy src/settings.php. Shared by the web side and the CLI.
 *
 * Values are validated on write and on read; a missing or invalid row falls
 * back to the default. They are cached in this instance, which
 * Config\Services builds once per request, so nothing outlives the request
 * (the legacy static settings_cache() is not used).
 *
 * autofill_token is write-only: all(), get() and export() never return it;
 * autofillToken() is the one getter, for the autofill client.
 *
 * Recoverable secrets (secret-type keys except password_hash) are sealed at
 * rest through SecretBox when APP_KEY is set (S-L5, legacy src/secretbox.php):
 * a legacy plaintext row is sealed on read, and a configured key that cannot
 * be used refuses the save with a 422.
 */
final class SettingsService
{
    /** Largest settings file import() accepts by default (legacy /settings/import). */
    public const IMPORT_MAX_BYTES = 256 * 1024;

    public const TOKEN_CLEARED_NOTICE = 'The AI autofill token was cleared because the service address changed: enter it again under AI autofill.';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @var array<string,mixed>|null every key, typed, secrets included */
    private ?array $values = null;

    private ?SettingsSchema $resolved = null;

    private bool $tokenCleared = false;

    /** @var Closure(string):void */
    private readonly Closure $log;

    private readonly ActorGate $actor;

    private readonly SecretBox $secrets;

    /**
     * @param array<string,mixed> $config ApothekeConfig values (for config-backed defaults)
     * @param (Closure(string):void)|null $log warning sink; error_log() like legacy autofill_log()
     * @param ?ActorGate $actor who imports (SEC-M1); none = not an admin
     * @param ?SecretBox $secrets at-rest sealing; none = APP_KEY and APP_KEY_FILE from the environment
     */
    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly SettingsSchema $schema,
        private readonly BrandStore $brand,
        private readonly Clock $clock,
        private readonly array $config,
        ?Closure $log = null,
        ?ActorGate $actor = null,
        ?SecretBox $secrets = null,
    ) {
        $this->log = $log ?? static function (string $message): void {
            error_log($message);
        };
        $this->actor = $actor ?? new Nobody();
        $this->secrets = $secrets ?? SecretBox::fromEnvironment(getenv(), self::backupRoots($config), $this->log);
    }

    /**
     * The data directory and every directory a backup can contain, where
     * APP_KEY_FILE is refused (legacy secret_backup_roots()).
     *
     * @param array<string,mixed> $config ApothekeConfig values
     * @return list<string>
     */
    public static function backupRoots(array $config): array
    {
        $data = rtrim((string)($config['data_dir'] ?? ''), '/');
        if ($data === '') {
            return [];
        }
        $roots = [$data, $data . '/images', $data . '/thumbs', $data . '/files', $data . '/originals'];
        $originals = trim((string)($config['originals_dir'] ?? ''));
        if ($originals !== '') {
            $roots[] = rtrim($originals[0] === '/' ? $originals : dirname(__DIR__, 3) . '/' . $originals, '/');
        }

        return array_values(array_unique($roots));
    }

    /** Whether $key is a recoverable secret sealed at rest (legacy secret_is_at_rest()). */
    public function isAtRest(string $key): bool
    {
        return $key !== 'password_hash' && $this->schema->isSecret($key);
    }

    /** The schema, validating `role` settings against the install's account roles. */
    public function schema(): SettingsSchema
    {
        return $this->resolved ??= $this->schema->withAccountRoles($this->repository->accountRoles() ?? SettingsSchema::BUILTIN_ACCOUNT_ROLES);
    }

    /**
     * Every readable setting, typed (secrets left out).
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->values() as $key => $value) {
            if (!$this->schema->isSecret($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        $values = $this->values();
        if (!array_key_exists($key, $values)) {
            throw new LogicException("Unknown setting $key");
        }
        if ($this->schema->isSecret($key)) {
            throw new LogicException("Setting $key is write-only");
        }

        return $values[$key];
    }

    /** The stored AI autofill token ('' = none). Only the autofill client should call this. */
    public function autofillToken(): string
    {
        return (string)($this->values()['autofill_token'] ?? '');
    }

    /** Store a new token (legacy /settings/autofill/token): 1–512 printable ASCII characters. */
    public function setAutofillToken(string $token): void
    {
        $token = trim($token);
        if (!preg_match('/^[\x21-\x7E]{1,512}$/D', $token)) {
            throw new UserError('Token: 1–512 printable characters, no spaces.', 422);
        }
        $this->store(['autofill_token' => $token]);
    }

    public function clearAutofillToken(): void
    {
        $this->store(['autofill_token' => '']);
    }

    /** Whether the last save, reset or store cleared the autofill token (legacy settings_token_cleared()). */
    public function tokenCleared(): bool
    {
        return $this->tokenCleared;
    }

    /**
     * Whether sign-in is on, for the CLI `list` line. Read-only port of legacy
     * auth_enabled(): on when the setting is on and an account exists, or
     * APP_PASSWORD is set. Legacy also creates the "admin" account from
     * APP_PASSWORD at that point; that write belongs to the Auth module.
     */
    public function signInEnabled(): bool
    {
        if (!$this->get('auth_enabled')) {
            return false;
        }

        return $this->repository->userCount() > 0 || (string)($this->config['app_password'] ?? '') !== '';
    }

    /** Number of accounts (legacy users_count()), for the CLI `list` line. */
    public function accountCount(): int
    {
        return $this->repository->userCount();
    }

    /**
     * Validate and save raw values. Nothing is saved when any value is invalid.
     *
     * @param array<array-key,mixed> $raw key => raw value
     * @param array<string,mixed> $extra already-validated values saved together (e.g. a new token)
     * @return array<string,string> errors by key (empty = saved)
     */
    public function save(array $raw, array $extra = []): array
    {
        $ok = $err = [];
        $this->tokenCleared = false;
        foreach ($raw as $key => $value) {
            try {
                $ok[(string)$key] = $this->schema()->validate((string)$key, $value);
            } catch (UserError $e) {
                $err[(string)$key] = $e->getMessage();
            }
        }
        if ($err === []) {
            try {
                $this->store($ok + $extra);   // $extra: already-validated values saved together (e.g. a new token)
            } catch (UserError $e) {
                $err['autofill_token'] = $e->getMessage();   // a configured key that cannot be used (#78 M-1)
            }
        }

        return $err;
    }

    /** Back to the config/built-in defaults for one section. The token and password are never touched. */
    public function resetSection(string $section): void
    {
        if (!isset(SettingsSchema::SECTIONS[$section])) {
            throw new UserError('Unknown section.', 404);
        }
        $keys = $this->schema->sectionKeys($section);
        if ($section === 'appearance') {
            $keys[] = 'logo';
            $this->brand->clear();
            $this->store(['logo' => '']);
        }
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->schema->default($key, $this->config);
        }
        $this->store($values);
    }

    /**
     * Exportable settings: no secrets, no internal values.
     *
     * @return array{format:string,version:int,exported_at:string,settings:array<string,mixed>}
     */
    public function export(): array
    {
        $out = [];
        foreach ($this->values() as $key => $value) {
            if (!$this->schema->isProtected($key)) {
                $out[$key] = $value;
            }
        }

        return ['format' => 'apotheke-settings', 'version' => 1, 'exported_at' => $this->clock->now(), 'settings' => $out];
    }

    /**
     * Import an export. Valid known keys are applied together; invalid ones
     * are reported and skipped, unknown and protected ones ignored, and so are
     * the sign-in (security) settings unless the actor is an admin or the
     * command line (SEC-M1). A backup restore goes through here too. Moving
     * the autofill address clears the stored token.
     *
     * @param ?int $maxBytes larger input is refused; null = no limit (the CLI, like legacy)
     * @return array{applied:int,errors:array<string,string>,ignored:list<string>,token_cleared:bool}
     */
    public function import(string $json, ?int $maxBytes = self::IMPORT_MAX_BYTES): array
    {
        if ($maxBytes !== null && strlen($json) > $maxBytes) {
            throw new UserError('That settings file is too large.');
        }
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['format'] ?? '') !== 'apotheke-settings' || !is_array($d['settings'] ?? null)) {
            throw new UserError('That is not an Apotheke settings file.');
        }
        $ok = $err = $ignored = [];
        foreach ($d['settings'] as $key => $value) {
            $key = (string)$key;
            if (!$this->schema->has($key) || $this->schema->isProtected($key)) {
                $ignored[] = $key;
                continue;
            }
            // Sign-in settings are the same actions as granting admin and turning
            // sign-in off: an admin account, or the command line (SEC-M1).
            if ($this->schema->section($key) === 'security' && !$this->actor->isAdmin()) {
                $ignored[] = $key;
                continue;
            }
            try {
                $ok[$key] = $this->schema()->validate($key, is_bool($value) ? $value : (is_scalar($value) || $value === null ? (string)$value : $value));
            } catch (UserError $e) {
                $err[$key] = $e->getMessage();
            }
        }
        $cleared = $this->store($ok);

        return ['applied' => count($ok), 'errors' => $err, 'ignored' => $ignored, 'token_cleared' => $cleared];
    }

    /**
     * Store already-validated values in one transaction (legacy settings_store()).
     * When autofill_url moves and no token is written in the same store, the
     * old token is dropped so it can never follow the address elsewhere.
     *
     * @param array<string,mixed> $values
     * @return bool whether the autofill token was cleared
     */
    public function store(array $values): bool
    {
        if ($values === []) {
            return false;
        }
        $moves = array_key_exists('autofill_url', $values) && !array_key_exists('autofill_token', $values);
        $oldUrl = $moves ? (string)$this->values()['autofill_url'] : '';
        $encoded = [];
        foreach ($values as $key => $value) {
            if ($this->isAtRest((string)$key) && is_string($value) && $value !== '') {
                $value = $this->secrets->seal($value);   // throws before anything is written
            }
            $encoded[$key] = (string)json_encode($value, self::JSON);
        }
        $this->repository->replace($encoded, $this->clock->now());
        $this->values = null;
        $cleared = $moves && $this->dropTokenIfMoved($oldUrl);
        $this->tokenCleared = $cleared;

        return $cleared;
    }

    /** @return array<string,mixed> every key, typed */
    private function values(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        $rows = $this->repository->rows();
        $out = [];
        $upgrade = [];
        foreach ($this->schema->keys() as $key) {
            if (array_key_exists($key, $rows)) {
                try {
                    $decoded = json_decode($rows[$key], true);
                    if ($this->isAtRest($key) && is_string($decoded) && $decoded !== '') {
                        if (SecretBox::isSealed($decoded)) {
                            $decoded = $this->secrets->open($key, $decoded);
                        } elseif ($this->secrets->key() !== null) {
                            $upgrade[$key] = $decoded;   // legacy plaintext row: seal it now
                        }
                    }
                    $out[$key] = $this->schema()->validate($key, $decoded);
                    continue;
                } catch (UserError) {
                    // invalid row: default
                }
            }
            $out[$key] = $this->schema->default($key, $this->config);
        }
        $this->values = $out;
        if ($upgrade !== []) {
            $this->sealPlaintextRows($upgrade);
        }

        return $out;
    }

    /**
     * Seal legacy plaintext secret rows in place (legacy secret_persist()).
     * A failure leaves the row; the next save seals it.
     *
     * @param array<string,string> $plainByName
     */
    private function sealPlaintextRows(array $plainByName): void
    {
        try {
            $encoded = [];
            foreach ($plainByName as $key => $plain) {
                if ($this->secrets->key() === null) {
                    continue;
                }
                $encoded[$key] = (string)json_encode($this->secrets->seal($plain), self::JSON);
            }
            $this->repository->replace($encoded, $this->clock->now());
        } catch (Throwable) {
            // leave the legacy row
        }
    }

    /** Whether the settings row of $name holds a non-empty string, before decryption (legacy secret_setting_nonempty()). */
    private function rowNonEmpty(string $name): bool
    {
        $row = $this->repository->rows()[$name] ?? null;
        if (!is_string($row)) {
            return false;
        }
        $decoded = json_decode($row, true);

        return is_string($decoded) && $decoded !== '';
    }

    private function dropTokenIfMoved(string $oldUrl): bool
    {
        // The stored row, not the decrypted value: a token sealed with another key still moves with the address.
        if (!$this->rowNonEmpty('autofill_token')) {
            return false;
        }
        if (self::normaliseUrl($oldUrl) === self::normaliseUrl((string)$this->values()['autofill_url'])) {
            return false;
        }
        $this->store(['autofill_token' => '']);
        ($this->log)('apotheke autofill: token cleared: the service address changed');

        return true;
    }

    /** Legacy autofill_normalise_url(): trailing slashes off, scheme and host lower-cased. */
    private static function normaliseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return (string)preg_replace_callback('#^([a-z][a-z0-9+.-]*://)([^/?\#@]+)#i', static fn (array $m): string => strtolower($m[1] . $m[2]), $url);
    }
}
