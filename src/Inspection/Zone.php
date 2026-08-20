<?php

declare(strict_types=1);

namespace SecondStay\Inspection;

/**
 * Une zone du logement, dans une langue donnée.
 */
final class Zone
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly int $position,
        public readonly bool $photoRequired,
        public readonly bool $active,
        public readonly string $referenceNote,
        public readonly string $name,
        public readonly string $instructions,
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
            (int) $row['position'],
            (bool) $row['photo_required'],
            (bool) $row['active'],
            (string) $row['reference_note'],
            (string) ($row['name'] ?? ''),
            (string) ($row['instructions'] ?? ''),
        );
    }

    /**
     * Nom saisi par le propriétaire, ou chaîne vide.
     *
     * L'affichage retombe alors sur `labelKey()` : le libellé intégré existe
     * déjà dans les quatre langues, une zone sans nom propre reste donc
     * lisible partout.
     */
    public function label(): string
    {
        return $this->name;
    }

    public function labelKey(): string
    {
        return 'inspection.zone.' . $this->code;
    }
}
