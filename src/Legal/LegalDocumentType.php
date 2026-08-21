<?php

declare(strict_types=1);

namespace SecondStay\Legal;

/**
 * Textes légaux versionnés (SPECIFICATIONS.md §65).
 *
 * Trois textes, parce que trois engagements distincts : ce que le voyageur
 * accepte en réservant (conditions), ce qu'il accepte en créant un compte
 * (confidentialité), et le règlement du logement lui-même.
 */
enum LegalDocumentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case HouseRules = 'house_rules';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Terms;
    }

    /**
     * Page éditoriale dont le texte est figé au moment de la publication.
     *
     * Le règlement du logement n'a pas de page publique : il vit dans le
     * livret d'accueil, et sa version est saisie directement.
     */
    public function contentSlug(): string
    {
        return match ($this) {
            self::Terms => 'terms',
            self::Privacy => 'privacy',
            self::HouseRules => '',
        };
    }

    /**
     * Ce texte est-il accepté au moment de réserver ?
     *
     * @return list<self>
     */
    public static function acceptedOnBooking(): array
    {
        return [self::Terms, self::HouseRules];
    }

    /**
     * Ce texte est-il accepté à la création d'un compte ?
     *
     * @return list<self>
     */
    public static function acceptedOnSignup(): array
    {
        return [self::Terms, self::Privacy];
    }

    public function labelKey(): string
    {
        return 'legal.type.' . $this->value;
    }
}
