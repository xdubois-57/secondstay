<?php

declare(strict_types=1);

namespace SecondStay\Legal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Une version publiée d'un texte légal, dans une langue.
 *
 * Elle est **immuable** : republier produit une nouvelle version, jamais une
 * modification. C'est la seule façon qu'une acceptation passée garde un sens.
 */
final class LegalDocument
{
    public function __construct(
        public readonly int $id,
        public readonly LegalDocumentType $type,
        public readonly string $locale,
        public readonly string $version,
        public readonly string $title,
        public readonly string $body,
        public readonly string $sha256,
        public readonly string $publishedAt,
        public readonly ?int $publishedBy,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            LegalDocumentType::fromString((string) $row['type']),
            (string) $row['locale'],
            (string) $row['version'],
            (string) $row['title'],
            (string) $row['body'],
            (string) $row['sha256'],
            (string) $row['published_at'],
            $row['published_by'] === null ? null : (int) $row['published_by'],
        );
    }

    public function publishedDate(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->publishedAt, new DateTimeZone('UTC'));
    }

    /**
     * Le corps stocké correspond-il toujours à son empreinte ?
     */
    public function isIntact(): bool
    {
        return hash('sha256', $this->body) === $this->sha256;
    }
}
