<?php

declare(strict_types=1);

namespace SecondStay\Stay;

/**
 * Un bloc du livret d'accueil, dans une langue.
 */
final class StayInfoBlock
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $locale,
        public readonly string $title,
        public readonly string $body,
        public readonly string $phase,
        public readonly int $position,
        public readonly bool $published,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['locale'],
            (string) $row['title'],
            (string) ($row['body'] ?? ''),
            (string) $row['phase'],
            (int) $row['position'],
            (bool) $row['published'],
            (string) $row['updated_at'],
        );
    }

    public function isEmpty(): bool
    {
        return trim($this->title) === '' && trim($this->body) === '';
    }

    /**
     * Le bloc concerne-t-il cette phase du séjour ?
     */
    public function appliesTo(StayPhase $phase): bool
    {
        return $this->phase === StayPhase::ANY || $this->phase === $phase->value;
    }

    public function labelKey(): string
    {
        return 'stay.block.' . $this->code;
    }
}
