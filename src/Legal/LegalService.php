<?php

declare(strict_types=1);

namespace SecondStay\Legal;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Booking\Booking;
use SecondStay\Content\ContentRepository;
use SecondStay\I18n\Locales;
use SecondStay\Logging\Logger;
use SecondStay\Settings\SettingsService;

/**
 * Textes légaux versionnés et consentements (SPECIFICATIONS.md §65).
 *
 * Le principe tient en une phrase : **on ne peut opposer à quelqu'un que ce
 * qu'il a réellement lu**. Une version publiée est donc un instantané figé du
 * texte, dans une langue donnée, et une acceptation enregistre la version, la
 * langue et l'empreinte du texte — pas un simple « oui ».
 *
 * Le texte lui-même continue de vivre là où le propriétaire l'écrit : dans
 * les pages éditoriales. Publier une version en prend une photo ; éditer la
 * page ensuite ne réécrit aucune version déjà publiée.
 */
final class LegalService
{
    /** Version créée à l'installation, avant toute publication manuelle. */
    public const INITIAL_VERSION = '1';

    public function __construct(
        private readonly LegalDocumentRepository $documents,
        private readonly BookingConsentRepository $consents,
        private readonly ContentRepository $content,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Publie une version d'un texte dans toutes les langues où il existe.
     *
     * Publier langue par langue produirait des versions partielles : un
     * voyageur néerlandais accepterait alors une « version 3 » qui n'existe
     * qu'en français.
     *
     * @return array{ok: bool, published: list<string>, missing: list<string>, error: string}
     */
    public function publish(LegalDocumentType $type, string $version, ?User $actor = null): array
    {
        $version = $this->normaliseVersion($version);
        if ($version === '') {
            return ['ok' => false, 'published' => [], 'missing' => [], 'error' => 'legal.error.version_required'];
        }

        $texts = $this->textsFor($type);
        if ($texts === []) {
            return ['ok' => false, 'published' => [], 'missing' => Locales::ALL, 'error' => 'legal.error.no_text'];
        }

        $published = [];
        $missing = [];

        foreach (Locales::ALL as $locale) {
            if (!isset($texts[$locale])) {
                $missing[] = $locale;
                continue;
            }

            $result = $this->documents->publish(
                $type,
                $locale,
                $version,
                $texts[$locale]['title'],
                $texts[$locale]['body'],
                $actor?->id,
            );

            if ($result['created']) {
                $published[] = $locale;
            }
        }

        if ($published === [] && $missing === []) {
            // Tout existait déjà sous ce numéro : republier ne dit rien de
            // neuf, et écraserait une preuve si on le permettait.
            return ['ok' => false, 'published' => [], 'missing' => [], 'error' => 'legal.error.already_published'];
        }

        // Le contrat cite la version des conditions : elle doit suivre.
        if ($type === LegalDocumentType::Terms && $published !== []) {
            $this->settings->set('legal.terms_version', $version, $actor?->email, $actor?->id);
        }

        $this->audit?->record('legal.published', 'legal_document', $type->value . ':' . $version, null, [
            'locales' => $published,
            'missing' => $missing,
        ], $actor?->id, $actor === null ? '' : $actor->email);

        $this->logger->info('legal', 'Texte légal publié', [
            'type' => $type->value,
            'version' => $version,
            'locales' => count($published),
        ]);

        return ['ok' => true, 'published' => $published, 'missing' => $missing, 'error' => ''];
    }

    /**
     * Version en vigueur d'un texte, dans la langue demandée.
     *
     * Le repli sur la langue par défaut est explicite : mieux vaut un texte
     * lisible dans une autre langue qu'aucun texte du tout, et l'acceptation
     * enregistrera la langue réellement servie.
     */
    public function current(LegalDocumentType $type, string $locale): ?LegalDocument
    {
        $locale = Locales::isSupported($locale) ? $locale : Locales::FALLBACK;

        $document = $this->documents->current($type, $locale);
        if ($document !== null) {
            return $document;
        }

        $fallback = $this->settings->string('site.default_locale');
        if ($fallback !== '' && $fallback !== $locale) {
            return $this->documents->current($type, $fallback);
        }

        return null;
    }

    public function currentVersion(LegalDocumentType $type, string $locale): string
    {
        $document = $this->current($type, $locale);

        return $document === null ? '' : $document->version;
    }

    /**
     * Enregistre ce que ce séjour vient d'accepter.
     *
     * @return list<BookingConsent>
     */
    public function recordBookingAcceptance(Booking $booking, string $locale, string $ip = ''): array
    {
        $locale = Locales::isSupported($locale) ? $locale : $booking->locale;
        // L'adresse n'est jamais conservée en clair : seule son empreinte
        // sert de preuve, comme pour l'acceptation d'un contrat.
        $ipHash = $ip === '' ? '' : hash('sha256', $ip);

        foreach (LegalDocumentType::acceptedOnBooking() as $type) {
            $document = $this->current($type, $locale);
            if ($document === null) {
                // Rien n'est publié : on n'invente pas une version. L'absence
                // se voit alors dans l'assistant conformité, plutôt que de se
                // déguiser en consentement.
                continue;
            }

            $this->consents->record(
                $booking->id,
                $type,
                $document->version,
                $document->locale,
                $document->id,
                $document->sha256,
                $ipHash,
            );
        }

        return $this->consents->forBooking($booking->id);
    }

    /**
     * @return list<BookingConsent>
     */
    public function acceptanceFor(Booking $booking): array
    {
        return $this->consents->forBooking($booking->id);
    }

    /**
     * Le texte accepté est-il toujours celui que la base conserve ?
     */
    public function acceptanceIsIntact(BookingConsent $consent): bool
    {
        if ($consent->documentId === null) {
            return false;
        }

        $document = $this->documents->findById($consent->documentId);

        return $document !== null
            && $document->sha256 === $consent->sha256
            && $document->isIntact();
    }

    /**
     * Publie la version initiale : le produit n'est jamais livré sans texte
     * opposable.
     */
    public function publishInitialVersion(): void
    {
        foreach ([LegalDocumentType::Terms, LegalDocumentType::Privacy] as $type) {
            if ($this->documents->versions($type) !== []) {
                continue;
            }

            $this->publish($type, self::INITIAL_VERSION);
        }
    }

    /**
     * Couverture linguistique de chaque version publiée.
     *
     * @return array<string, list<string>>
     */
    public function coverage(LegalDocumentType $type): array
    {
        return $this->documents->coverage($type);
    }

    /**
     * @return list<LegalDocument>
     */
    public function versions(LegalDocumentType $type): array
    {
        return $this->documents->versions($type);
    }

    // --- Interne ------------------------------------------------------------------

    /**
     * Textes actuels d'un type, langue par langue.
     *
     * @return array<string, array{title: string, body: string}>
     */
    private function textsFor(LegalDocumentType $type): array
    {
        $slug = $type->contentSlug();
        if ($slug === '') {
            return [];
        }

        $page = $this->content->findBySlug($slug);
        if ($page === null) {
            return [];
        }

        $texts = [];
        foreach (Locales::ALL as $locale) {
            $translation = $page->translations[$locale] ?? null;
            if ($translation === null || trim($translation->body) === '') {
                continue;
            }

            $texts[$locale] = ['title' => $translation->title, 'body' => $translation->body];
        }

        return $texts;
    }

    /**
     * Un numéro de version est une étiquette, pas du texte libre : il finit
     * dans un contrat et dans une preuve d'acceptation.
     */
    private function normaliseVersion(string $version): string
    {
        $version = trim($version);
        $version = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $version);

        return mb_substr(trim($version, '-'), 0, 32);
    }
}
