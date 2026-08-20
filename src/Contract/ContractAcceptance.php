<?php

declare(strict_types=1);

namespace SecondStay\Contract;

/**
 * Trace d'acceptation d'un contrat (SPECIFICATIONS.md §40).
 *
 * Elle porte la version **et** la langue du texte réellement présenté, avec
 * l'empreinte du PDF accepté : rejouer l'historique d'un séjour redonne ce que
 * le client a lu, et non la version courante du modèle.
 */
final class ContractAcceptance
{
    public function __construct(
        public readonly int $id,
        public readonly int $bookingId,
        public readonly ?int $documentId,
        public readonly string $version,
        public readonly string $locale,
        public readonly string $sha256,
        public readonly ?int $userId,
        public readonly string $acceptedBy,
        public readonly string $ipHash,
        public readonly string $userAgent,
        public readonly string $acceptedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['booking_id'],
            $row['document_id'] === null ? null : (int) $row['document_id'],
            (string) $row['version'],
            (string) $row['locale'],
            (string) $row['sha256'],
            $row['user_id'] === null ? null : (int) $row['user_id'],
            (string) $row['accepted_by'],
            (string) $row['ip_hash'],
            (string) $row['user_agent'],
            (string) $row['accepted_at'],
        );
    }

    public function shortHash(): string
    {
        return substr($this->sha256, 0, 12);
    }
}
