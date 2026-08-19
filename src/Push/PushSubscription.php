<?php

declare(strict_types=1);

namespace SecondStay\Push;

use InvalidArgumentException;

/**
 * Abonnement au push transmis par le navigateur.
 *
 * Les clés sont conservées telles que le navigateur les fournit (base64url) ;
 * leur forme est validée dès la construction, jamais au moment de l'envoi.
 */
final class PushSubscription
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $publicKey,
        public readonly string $authSecret,
        public readonly int $userId = 0,
        public readonly string $locale = 'fr',
        public readonly int $id = 0,
    ) {
        if (!self::isValidEndpoint($endpoint)) {
            throw new InvalidArgumentException('push.error.invalid_endpoint');
        }

        if (strlen(Base64Url::decode($publicKey)) !== 65) {
            throw new InvalidArgumentException('push.error.invalid_subscription_key');
        }

        if (strlen(Base64Url::decode($authSecret)) !== 16) {
            throw new InvalidArgumentException('push.error.invalid_subscription_key');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['endpoint'],
            (string) $row['public_key'],
            (string) $row['auth_secret'],
            (int) $row['user_id'],
            (string) $row['locale'],
            (int) $row['id'],
        );
    }

    /**
     * Un endpoint de push est toujours une URL HTTPS absolue.
     */
    public static function isValidEndpoint(string $endpoint): bool
    {
        if (strlen($endpoint) > 2000 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return str_starts_with($endpoint, 'https://');
    }

    public function binaryPublicKey(): string
    {
        return Base64Url::decode($this->publicKey);
    }

    public function binaryAuthSecret(): string
    {
        return Base64Url::decode($this->authSecret);
    }

    /**
     * Empreinte stable de l'endpoint : elle sert de clé d'unicité sans
     * indexer une URL de 2000 caractères.
     */
    public function endpointHash(): string
    {
        return hash('sha256', $this->endpoint);
    }

    /**
     * Origine du service de push, seule partie affichable sans risque.
     */
    public function serviceHost(): string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
