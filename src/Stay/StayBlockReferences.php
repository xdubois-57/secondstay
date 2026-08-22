<?php

declare(strict_types=1);

namespace SecondStay\Stay;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Carte et source attachées à un bloc du livret (SPECIFICATIONS.md §55).
 *
 * Deux besoins que le texte libre ne couvre pas :
 *
 * - la **carte** : « au bout de la rue à gauche » ne se suit pas depuis un
 *   téléphone, dans le noir, avec une valise ; un lien ouvrable s'ouvre dans
 *   l'application de navigation du voyageur ;
 * - la **source** : les jours de collecte, les horaires de déchèterie et les
 *   arrêtés municipaux changent ; un livret qui affirme sans dire d'où vient
 *   l'information ni quand elle a été vérifiée vieillit sans prévenir.
 *
 * Ces adresses ne sont **jamais récupérées par le serveur** : elles ne sont
 * qu'un `href`. Il n'y a donc pas de surface SSRF ici et `UrlGuard`, qui
 * protège les récupérations sortantes, n'a pas à intervenir. En revanche le
 * schéma est contrôlé : `javascript:` ou `data:` dans un `href` serait une
 * injection.
 */
final class StayBlockReferences
{
    /**
     * Longueurs retenues par le schéma. Une adresse plus longue est refusée
     * plutôt que tronquée : une URL coupée est un lien mort qui a l'air bon.
     */
    public const URL_MAX_LENGTH = 500;
    public const LABEL_MAX_LENGTH = 120;

    public function __construct(
        public readonly string $linkUrl = '',
        public readonly string $linkLabel = '',
        public readonly string $sourceUrl = '',
        public readonly ?string $checkedOn = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) ($row['link_url'] ?? ''),
            (string) ($row['link_label'] ?? ''),
            (string) ($row['source_url'] ?? ''),
            ($row['source_checked_on'] ?? null) === null ? null : (string) $row['source_checked_on'],
        );
    }

    /**
     * Construit depuis un formulaire, en refusant ce qui n'est pas utilisable.
     *
     * Rien n'est corrigé en silence : une adresse invalide est signalée au
     * propriétaire, parce qu'un lien de carte mort dans un livret ne se
     * remarque que le jour où un voyageur cherche le local à poubelles.
     *
     * @return array{ok: bool, error: string, value: self}
     */
    public static function fromInput(
        string $linkUrl,
        string $linkLabel,
        string $sourceUrl,
        string $checkedOn,
        ?string $today = null,
    ): array {
        $linkUrl = trim($linkUrl);
        $sourceUrl = trim($sourceUrl);
        $linkLabel = trim($linkLabel);
        $checkedOn = trim($checkedOn);

        if ($linkUrl !== '' && !self::isOpenableUrl($linkUrl)) {
            return ['ok' => false, 'error' => 'stay.error.link_url', 'value' => self::none()];
        }

        if ($sourceUrl !== '' && !self::isOpenableUrl($sourceUrl)) {
            return ['ok' => false, 'error' => 'stay.error.source_url', 'value' => self::none()];
        }

        $date = self::normaliseDate($checkedOn);

        if ($checkedOn !== '' && $date === null) {
            return ['ok' => false, 'error' => 'stay.error.source_date', 'value' => self::none()];
        }

        if ($sourceUrl === '') {
            // Une date de vérification sans source ne vérifie rien.
            $date = null;
        } elseif ($date === null) {
            // Une source sans date n'est pas vérifiable : la date du jour est
            // la seule honnête, comme pour la conformité (§12).
            $date = $today ?? gmdate('Y-m-d');
        }

        return [
            'ok' => true,
            'error' => '',
            'value' => new self(
                $linkUrl,
                // Un intitulé sans lien n'a rien à intituler.
                $linkUrl === '' ? '' : mb_substr($linkLabel, 0, self::LABEL_MAX_LENGTH),
                $sourceUrl,
                $date,
            ),
        ];
    }

    public function hasLink(): bool
    {
        return $this->linkUrl !== '';
    }

    public function hasSource(): bool
    {
        return $this->sourceUrl !== '';
    }

    /**
     * Texte du lien : l'intitulé choisi, à défaut le domaine.
     *
     * Un lien nu de 200 caractères n'est ni lisible ni cliquable au pouce.
     */
    public function linkText(): string
    {
        return $this->linkLabel !== '' ? $this->linkLabel : self::host($this->linkUrl);
    }

    public function sourceHost(): string
    {
        return self::host($this->sourceUrl);
    }

    public function checkedOnDate(): ?DateTimeImmutable
    {
        return $this->checkedOn === null
            ? null
            : new DateTimeImmutable($this->checkedOn . ' 00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * @return array{link_url: string, link_label: string, source_url: string, source_checked_on: string|null}
     */
    public function toRow(): array
    {
        return [
            'link_url' => $this->linkUrl,
            'link_label' => $this->linkLabel,
            'source_url' => $this->sourceUrl,
            'source_checked_on' => $this->checkedOn,
        ];
    }

    /**
     * L'adresse est-elle ouvrable sans danger depuis un `href` ?
     */
    private static function isOpenableUrl(string $url): bool
    {
        if (strlen($url) > self::URL_MAX_LENGTH) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    private static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    private static function normaliseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date === false ? null : $date->format('Y-m-d');
    }
}
