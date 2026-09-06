<?php

declare(strict_types=1);

namespace SecondStay\Police;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Booking\Booking;
use SecondStay\I18n\Locales;
use SecondStay\Logging\Logger;
use SecondStay\Settings\SettingsService;

/**
 * Fiches individuelles de police (SPECIFICATIONS.md §64).
 *
 * Trois règles, toutes issues de la spécification :
 *
 * 1. **seulement si applicable**. La fiche n'est exigée que dans certains
 *    cas ; tant que le propriétaire ne l'a pas activée, le produit ne collecte
 *    rien. Collecter « au cas où » une identité et un domicile serait
 *    exactement l'inverse de la minimisation ;
 * 2. **données chiffrées**. Le contenu ne vit jamais en clair en base ;
 * 3. **rétention automatique**. La durée de conservation est configurée,
 *    documentée dans la fiche elle-même, et la purge l'applique sans qu'on
 *    ait à y penser.
 */
final class PoliceRecordService
{
    /** Durée de conservation par défaut, en jours. */
    public const DEFAULT_RETENTION_DAYS = 183;

    /**
     * Champs de la fiche.
     *
     * Ce sont ceux du formulaire réglementaire, et rien de plus : chaque champ
     * supplémentaire serait une donnée personnelle collectée sans raison.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'last_name',
        'first_names',
        'birth_date',
        'birth_place',
        'nationality',
        'home_address',
        'arrival_date',
        'departure_date',
    ];

    /** Champs sans lesquels la fiche n'a aucune valeur. */
    private const REQUIRED = ['last_name', 'first_names', 'birth_date', 'nationality'];

    public function __construct(
        private readonly PoliceRecordRepository $records,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool('compliance.police_record_enabled');
    }

    public function retentionDays(): int
    {
        $days = $this->settings->int('compliance.police_retention_days');

        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Enregistre ou met à jour la fiche d'un séjour.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, error: string}
     */
    public function save(Booking $booking, array $input, string $locale, ?User $actor = null): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'error' => 'police.error.disabled'];
        }

        $fields = [];
        foreach (self::FIELDS as $name) {
            $fields[$name] = mb_substr(trim((string) ($input[$name] ?? '')), 0, 190);
        }

        foreach (self::REQUIRED as $name) {
            if ($fields[$name] === '') {
                return ['ok' => false, 'error' => 'police.error.incomplete'];
            }
        }

        $this->records->save(
            $booking->id,
            $fields,
            Locales::isSupported($locale) ? $locale : $booking->locale,
            $this->purgeDateFor($booking),
            $actor?->id,
        );

        // Jamais le contenu dans l'audit : seulement le fait qu'une fiche
        // existe pour ce séjour.
        $this->audit?->record('police.recorded', 'booking', (string) $booking->id, null, [
            'purge_after' => $this->purgeDateFor($booking),
        ], $actor?->id, $actor === null ? '' : $actor->email);

        return ['ok' => true, 'error' => ''];
    }

    public function forBooking(Booking $booking): ?PoliceRecord
    {
        return $this->records->forBooking($booking->id);
    }

    /**
     * @return list<PoliceRecord>
     */
    public function all(): array
    {
        return $this->records->all();
    }

    public function delete(Booking $booking, ?User $actor = null): void
    {
        $this->records->delete($booking->id);

        $this->audit?->record(
            'police.deleted',
            'booking',
            (string) $booking->id,
            null,
            null,
            $actor?->id,
            $actor === null ? '' : $actor->email,
        );
    }

    /**
     * Applique la rétention : les fiches échues disparaissent.
     */
    public function purge(?string $today = null): int
    {
        $removed = $this->records->purgeExpired($today);

        if ($removed > 0) {
            $this->logger->info('police', 'Fiches de police purgées', ['count' => $removed]);
            $this->audit?->record('police.purged', 'police_record', 'retention', null, ['count' => $removed]);
        }

        return $removed;
    }

    /**
     * Date au-delà de laquelle la fiche est effacée.
     *
     * Le compte part du **départ** : conserver depuis la création
     * commencerait à courir avant même le séjour.
     */
    public function purgeDateFor(Booking $booking): string
    {
        return $booking->range->departure
            ->modify('+' . $this->retentionDays() . ' days')
            ->format('Y-m-d');
    }
}
