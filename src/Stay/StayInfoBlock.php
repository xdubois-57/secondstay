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
        /**
         * Illustration choisie dans la médiathèque (SPECIFICATIONS.md §55).
         *
         * Une image explique le tri des déchets ou la manœuvre d'un appareil
         * mieux qu'un paragraphe, et se lit dans n'importe quelle langue.
         */
        public readonly ?int $mediaId,
        public readonly string $phase,
        public readonly int $position,
        public readonly bool $published,
        /**
         * Le bloc est-il servi à une adresse publique stable, destinée à un
         * QR physique (SPECIFICATIONS.md §47) ?
         *
         * Distinct de `published` : « publié » veut dire visible par le
         * voyageur dans « Mon séjour », « public » veut dire lisible par
         * quiconque connaît l'adresse, sans séjour ni compte.
         */
        public readonly bool $isPublic,
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
            isset($row['media_id']) ? (int) $row['media_id'] : null,
            (string) $row['phase'],
            (int) $row['position'],
            (bool) $row['published'],
            (bool) ($row['public'] ?? false),
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

    /**
     * Le bloc peut-il être servi à son adresse publique ?
     *
     * Les deux conditions comptent : un bloc dépublié du livret ne doit pas
     * rester lisible par une adresse que le propriétaire a oubliée, et un bloc
     * vide n'a rien à montrer à quelqu'un qui vient de scanner un QR.
     */
    public function isPubliclyReadable(): bool
    {
        return $this->isPublic && $this->published && !$this->isEmpty();
    }
}
