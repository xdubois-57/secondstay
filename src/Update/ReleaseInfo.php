<?php

declare(strict_types=1);

namespace SecondStay\Update;

final class ReleaseInfo
{
    public function __construct(
        public readonly string $version,
        public readonly string $tag,
        public readonly string $assetName,
        public readonly string $assetUrl,
        public readonly int $assetSize,
        public readonly string $publishedAt,
        public readonly string $notes = '',
        public readonly bool $prerelease = false,
    ) {
    }

    /**
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
}
