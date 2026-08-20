<?php

declare(strict_types=1);

namespace SecondStay\Document;

/**
 * Document rattaché à un séjour.
 *
 * Le fichier lui-même vit hors du document root : seul son chemin relatif au
 * stockage est porté ici, jamais une URL publique.
 */
final class Document
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $bookingId,
        public readonly DocumentKind $kind,
        public readonly DocumentSource $source,
        public readonly string $filename,
        public readonly string $mime,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly string $storagePath,
        public readonly string $locale,
        public readonly string $version,
        public readonly ?int $mailId,
        public readonly ?int $uploadedBy,
        public readonly string $sender,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['booking_id'] === null ? null : (int) $row['booking_id'],
            DocumentKind::fromString((string) $row['kind']),
            DocumentSource::fromString((string) $row['source']),
            (string) $row['filename'],
            (string) $row['mime'],
            (int) $row['size_bytes'],
            (string) $row['sha256'],
            (string) $row['storage_path'],
            (string) $row['locale'],
            (string) $row['version'],
            $row['mail_id'] === null ? null : (int) $row['mail_id'],
            $row['uploaded_by'] === null ? null : (int) $row['uploaded_by'],
            (string) ($row['sender'] ?? ''),
            (string) $row['created_at'],
        );
    }

    /**
     * Taille lisible, en unités binaires.
     */
    public function humanSize(): string
    {
        $units = ['o', 'Kio', 'Mio', 'Gio'];
        $size = (float) $this->sizeBytes;
        $unit = 0;

        while ($size >= 1024.0 && $unit < count($units) - 1) {
            $size /= 1024.0;
            $unit++;
        }

        return $unit === 0
            ? sprintf('%d %s', (int) $size, $units[0])
            : sprintf('%.1f %s', $size, $units[$unit]);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    /**
     * Empreinte abrégée, telle qu'affichée à côté d'un contrat accepté.
     */
    public function shortHash(): string
    {
        return substr($this->sha256, 0, 12);
    }
}
