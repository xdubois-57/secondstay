<?php

declare(strict_types=1);

namespace SecondStay\Stay;

use SecondStay\Database\Database;
use SecondStay\Security\Encryptor;
use SensitiveParameter;
use Throwable;

/**
 * Secrets d'accès du logement : mot de passe Wi-Fi, code de boîte à clés,
 * code d'alarme.
 *
 * Ils sont chiffrés au repos comme n'importe quel secret de l'installation.
 * Ce ne sont pas des données publiques : un code d'alarme qui traîne en clair
 * dans une sauvegarde ou un dump de base est un incident de sécurité.
 */
final class StaySecretRepository
{
    /**
     * Secrets connus.
     *
     * @var list<string>
     */
    public const CODES = ['wifi_password', 'key_box', 'alarm', 'gate'];

    public function __construct(
        private readonly Database $database,
        private readonly Encryptor $encryptor,
    ) {
    }

    public function set(string $code, #[SensitiveParameter] string $value): void
    {
        if (!in_array($code, self::CODES, true)) {
            return;
        }

        $data = [
            'value' => $value === '' ? null : $this->encryptor->encrypt($value, 'stay:' . $code),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($this->raw($code) === null) {
            $this->database->insert('stay_secret', $data + ['code' => $code]);

            return;
        }

        $this->database->update('stay_secret', $data, ['code' => $code]);
    }

    /**
     * Valeur en clair, ou chaîne vide si absente ou illisible.
     *
     * Une valeur qu'on ne sait plus déchiffrer — clé rotée sans re-chiffrement
     * — ne doit pas faire tomber la page du séjour : elle est simplement
     * absente.
     */
    public function get(string $code): string
    {
        $stored = $this->raw($code);
        if ($stored === null || $stored === '') {
            return '';
        }

        try {
            return $this->encryptor->decrypt($stored, 'stay:' . $code);
        } catch (Throwable) {
            return '';
        }
    }

    public function isDefined(string $code): bool
    {
        return $this->get($code) !== '';
    }

    /**
     * Tous les secrets renseignés, en clair.
     *
     * Réservé à la construction de la page du séjour, à l'intérieur de la
     * fenêtre autorisée.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $values = [];

        foreach (self::CODES as $code) {
            $value = $this->get($code);
            if ($value !== '') {
                $values[$code] = $value;
            }
        }

        return $values;
    }

    /**
     * Aperçu masqué, pour l'administration.
     */
    public function preview(string $code): string
    {
        $value = $this->get($code);

        return $value === '' ? '' : Encryptor::mask($value);
    }

    private function raw(string $code): ?string
    {
        $row = $this->database->fetchOne(
            'SELECT `value` FROM `stay_secret` WHERE `code` = :code',
            ['code' => $code]
        );

        return $row === null || $row['value'] === null ? null : (string) $row['value'];
    }
}
