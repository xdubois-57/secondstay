<?php

declare(strict_types=1);

namespace SecondStay\Stay;

use SecondStay\Auth\User;
use SecondStay\Booking\Booking;

/**
 * « Mon séjour aujourd'hui », prêt à afficher (SPECIFICATIONS.md §45).
 *
 * Le modèle porte explicitement ce qui est **autorisé** à sortir : ni montant,
 * ni document, ni coordonnée d'un autre voyageur. Un gabarit ne peut donc pas
 * afficher par inadvertance ce que la page n'a pas le droit de montrer.
 */
final class StayView
{
    /**
     * @param list<StayInfoBlock>   $blocks
     * @param array<string, string> $secrets codes d'accès, vides hors séjour
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly StayPhase $phase,
        public readonly string $locale,
        public readonly array $blocks,
        public readonly array $secrets,
        public readonly ?User $manager,
        public readonly string $checkinTime,
        public readonly string $checkoutTime,
        public readonly bool $isGuest,
        public readonly int $nightsUntilArrival,
    ) {
    }

    public function hasSecrets(): bool
    {
        return $this->secrets !== [];
    }

    public function secret(string $code): string
    {
        return $this->secrets[$code] ?? '';
    }

    /**
     * Blocs concernant la phase courante.
     *
     * @return list<StayInfoBlock>
     */
    public function visibleBlocks(): array
    {
        return array_values(array_filter(
            $this->blocks,
            fn (StayInfoBlock $block): bool => $block->appliesTo($this->phase) && !$block->isEmpty()
        ));
    }

    /**
     * Le séjour est-il en cours ?
     */
    public function isOnSite(): bool
    {
        return $this->phase->isOnSite();
    }
}
