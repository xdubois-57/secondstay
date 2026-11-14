<?php

declare(strict_types=1);

namespace SecondStay\Update;

final class ReleaseInfo
{
    /**
     * @param string $digest empreinte SHA-256 de l'asset (64 hexadécimaux
     *                       minuscules) annoncée par la source, ou chaîne vide
     *                       si la source n'en publie pas — auquel cas la
     *                       release n'est pas installable
     */
    public function __construct(
        public readonly string $version,
        public readonly string $tag,
        public readonly string $assetName,
        public readonly string $assetUrl,
        public readonly int $assetSize,
        public readonly string $publishedAt,
        public readonly string $notes = '',
        public readonly bool $prerelease = false,
        public readonly string $digest = '',
    ) {
    }

    /**
     * Vue destinée à l'affichage : ni l'URL de téléchargement ni l'empreinte.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'tag' => $this->tag,
            'asset_name' => $this->assetName,
            'asset_size' => $this->assetSize,
            'published_at' => $this->publishedAt,
            'prerelease' => $this->prerelease,
        ];
    }

    /**
     * Forme complète, pour le cache de vérification.
     *
     * @return array{version: string, tag: string, asset_name: string, asset_url: string, asset_size: int,
     *     published_at: string, notes: string, prerelease: bool, digest: string}
     */
    public function toStorage(): array
    {
        return [
            'version' => $this->version,
            'tag' => $this->tag,
            'asset_name' => $this->assetName,
            'asset_url' => $this->assetUrl,
            'asset_size' => $this->assetSize,
            'published_at' => $this->publishedAt,
            'notes' => $this->notes,
            'prerelease' => $this->prerelease,
            'digest' => $this->digest,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromStorage(array $data): ?self
    {
        $version = $data['version'] ?? null;
        if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            return null;
        }

        $string = static fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';

        return new self(
            $version,
            $string('tag'),
            $string('asset_name'),
            $string('asset_url'),
            is_int($data['asset_size'] ?? null) ? $data['asset_size'] : 0,
            $string('published_at'),
            $string('notes'),
            ($data['prerelease'] ?? false) === true,
            $string('digest'),
        );
    }
}
