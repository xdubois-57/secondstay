<?php

declare(strict_types=1);

namespace SecondStay\Inspection;

/**
 * Constat pour une zone, avec ses photos.
 */
final class InspectionEntry
{
    /**
     * @param list<int> $photoIds
     */
    public function __construct(
        public readonly int $id,
        public readonly int $inspectionId,
        public readonly Zone $zone,
        public readonly EntryState $state,
        public readonly string $note,
        public readonly array $photoIds,
        public readonly string $updatedAt,
    ) {
    }

    public function hasPhoto(): bool
    {
        return $this->photoIds !== [];
    }

    /**
     * La zone est-elle prête pour un départ ?
     *
     * Une photo n'est exigée que là où le propriétaire l'a demandée, et
     * seulement au départ (SPECIFICATIONS.md §53).
     */
    public function isReadyForCheckout(): bool
    {
        if (!$this->state->isDecided()) {
            return false;
        }

        return !$this->zone->photoRequired || $this->hasPhoto();
    }
}
