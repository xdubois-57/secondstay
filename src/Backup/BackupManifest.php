<?php

declare(strict_types=1);

namespace SecondStay\Backup;

/**
 * Manifeste d'une sauvegarde : identité, contenu et empreintes.
 */
final class BackupManifest
{
    public const FORMAT_VERSION = 1;
    public const FILENAME = 'manifest.json';

    /**
     * @param array<string, string> $checksums chemin dans l'archive => sha256
     * @param array<string, int>    $tableRows table => nombre de lignes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $createdAt,
        public readonly string $appVersion,
        public readonly string $schemaVersion,
        public readonly array $checksums,
        public readonly array $tableRows,
        public readonly bool $includesMedia,
        public readonly int $formatVersion = self::FORMAT_VERSION,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format_version' => $this->formatVersion,
            'id' => $this->id,
            'created_at' => $this->createdAt,
            'app_version' => $this->appVersion,
            'schema_version' => $this->schemaVersion,
            'includes_media' => $this->includesMedia,
            'table_rows' => $this->tableRows,
            'checksums' => $this->checksums,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, string> $checksums */
        $checksums = is_array($data['checksums'] ?? null) ? $data['checksums'] : [];
        /** @var array<string, int> $tableRows */
        $tableRows = is_array($data['table_rows'] ?? null) ? $data['table_rows'] : [];

        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['created_at'] ?? ''),
            (string) ($data['app_version'] ?? ''),
            (string) ($data['schema_version'] ?? ''),
            $checksums,
            $tableRows,
            (bool) ($data['includes_media'] ?? false),
            (int) ($data['format_version'] ?? self::FORMAT_VERSION),
        );
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
