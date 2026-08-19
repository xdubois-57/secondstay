<?php

declare(strict_types=1);

namespace SecondStay\Settings;

use SecondStay\Audit\AuditTrail;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Security\Encryptor;

/**
 * Lecture/écriture des réglages typés.
 *
 * - les secrets sont chiffrés au repos et jamais réaffichés (SECURITY.md §11) ;
 * - toute modification de réglage sensible est auditée (AGENTS.md §17).
 */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /** @var list<string> modules dont la modification est auditée */
    private const AUDITED_MODULES = ['pricing', 'booking', 'update', 'backup', 'maintenance'];

    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly SettingsStore $repository,
        private readonly Encryptor $encryptor,
        private readonly SettingValidator $validator = new SettingValidator(),
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    public function registry(): SettingRegistry
    {
        return $this->registry;
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    public function get(string $key): mixed
    {
        $definition = $this->registry->get($key);
        $stored = $this->stored();

        if (!array_key_exists($key, $stored)) {
            return $definition->default;
        }

        $raw = $stored[$key];
        if ($raw === null) {
            return $definition->default;
        }

        if ($definition->isSecret()) {
            return $this->encryptor->decrypt((string) $raw, 'setting:' . $key);
        }

        return $this->decode($definition, (string) $raw);
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function bool(string $key): bool
    {
        return $this->get($key) === true;
    }

    public function money(string $key): int
    {
        return $this->int($key);
    }

    /**
     * Valeur masquée d'un secret : indique s'il est défini sans le divulguer.
     */
    public function secretPreview(string $key): string
    {
        $definition = $this->registry->get($key);
        if (!$definition->isSecret()) {
            return '';
        }

        $stored = $this->stored();
        $raw = $stored[$key] ?? null;
        if ($raw === null || $raw === '') {
            return '';
        }

        return Encryptor::mask($this->encryptor->decrypt((string) $raw, 'setting:' . $key));
    }

    public function isSecretDefined(string $key): bool
    {
        $stored = $this->stored();
        $raw = $stored[$key] ?? null;

        return $raw !== null && $raw !== '';
    }

    /**
     * Enregistre un ensemble de réglages validés.
     *
     * @param array<string, mixed> $values
     *
     * @throws ValidationException
     */
    public function setMany(array $values, ?string $actorLabel = null, ?int $actorId = null): void
    {
        $errors = [];
        $normalised = [];

        foreach ($values as $key => $raw) {
            if (!$this->registry->has($key)) {
                $errors[$key] = 'settings.error.unknown';
                continue;
            }

            $definition = $this->registry->get($key);

            // Un champ secret laissé vide conserve la valeur existante :
            // l'UI ne réaffiche jamais le secret.
            if ($definition->isSecret() && ($raw === null || $raw === '')) {
                continue;
            }

            $result = $this->validator->validate($definition, $raw);
            if ($result['ok'] === false) {
                $errors[$key] = $result['error'];
                continue;
            }

            $normalised[$key] = $result['value'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        foreach ($normalised as $key => $value) {
            $definition = $this->registry->get($key);
            $before = in_array($definition->module, self::AUDITED_MODULES, true) && !$definition->isSecret()
                ? $this->get($key)
                : null;

            if ($definition->isSecret()) {
                $encoded = $this->encryptor->encrypt((string) $value, 'setting:' . $key);
            } else {
                $encoded = $this->encode($definition, $value);
            }

            $this->repository->set($key, $encoded, $definition->isSecret());
            $this->cache = null;

            if ($this->audit !== null && in_array($definition->module, self::AUDITED_MODULES, true)) {
                $this->audit->record(
                    'settings.updated',
                    'setting',
                    $key,
                    $definition->isSecret() ? ['value' => '***'] : ['value' => $before],
                    $definition->isSecret() ? ['value' => '***'] : ['value' => $value],
                    $actorId,
                    $actorLabel ?? 'system',
                );
            }
        }
    }

    public function set(string $key, mixed $value, ?string $actorLabel = null, ?int $actorId = null): void
    {
        $this->setMany([$key => $value], $actorLabel, $actorId);
    }

    /**
     * Rechiffre tous les secrets avec la clé active (rotation de clé).
     *
     * @return list<string> clés rechiffrées
     */
    public function rotateSecrets(): array
    {
        $rotated = [];
        foreach ($this->repository->all() as $key => $entry) {
            if (!$entry['is_secret'] || $entry['value'] === null || $entry['value'] === '') {
                continue;
            }
            if ($this->encryptor->keyIdOf($entry['value']) === $this->encryptor->activeKeyId()) {
                continue;
            }
            $this->repository->set($key, $this->encryptor->rotate($entry['value'], 'setting:' . $key), true);
            $rotated[] = $key;
        }
        $this->cache = null;

        return $rotated;
    }

    /**
     * Export non sensible pour diagnostics et sauvegarde de configuration.
     *
     * @return array<string, mixed>
     */
    public function exportSafe(): array
    {
        $export = [];
        foreach ($this->registry->all() as $key => $definition) {
            $export[$key] = $definition->isSecret()
                ? ($this->isSecretDefined($key) ? '***defined***' : '')
                : $this->get($key);
        }

        return $export;
    }

    /**
     * @return array<string, ?string>
     */
    private function stored(): array
    {
        if ($this->cache !== null) {
            /** @var array<string, ?string> */
            return $this->cache;
        }

        $values = [];
        foreach ($this->repository->all() as $key => $entry) {
            $values[$key] = $entry['value'];
        }
        $this->cache = $values;

        return $values;
    }

    private function encode(SettingDefinition $definition, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($definition->type) {
            SettingType::Bool => $value === true ? '1' : '0',
            SettingType::Json => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    private function decode(SettingDefinition $definition, string $raw): mixed
    {
        return match ($definition->type) {
            SettingType::Bool => $raw === '1',
            SettingType::Integer, SettingType::Money, SettingType::Duration => (int) $raw,
            SettingType::Decimal => (float) $raw,
            SettingType::Json => is_array($decoded = json_decode($raw, true)) ? $decoded : [],
            default => $raw,
        };
    }
}
