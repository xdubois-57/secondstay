<?php

declare(strict_types=1);

namespace SecondStay\LocalContent;

/**
 * Une URL consultée pour produire le contenu local.
 */
final class LocalSource
{
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $label,
        public readonly bool $active,
        public readonly ?string $lastFetchAt,
        public readonly string $lastStatus,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['url'],
            (string) $row['label'],
            (bool) $row['active'],
            $row['last_fetch_at'] === null ? null : (string) $row['last_fetch_at'],
            (string) $row['last_status'],
        );
    }

    public function hasFailed(): bool
    {
        return $this->lastStatus !== '' && $this->lastStatus !== 'ok';
    }

    public function statusLabelKey(): string
    {
        return $this->lastStatus === '' ? 'local.source.never_fetched' : 'local.source.status.' . $this->lastStatus;
    }
}
