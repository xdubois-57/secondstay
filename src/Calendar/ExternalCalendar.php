<?php

declare(strict_types=1);

namespace SecondStay\Calendar;

/**
 * Un flux ICS externe déclaré par le propriétaire.
 */
final class ExternalCalendar
{
    /** Plateformes reconnues : le libellé change, le traitement non. */
    public const PROVIDERS = ['airbnb', 'booking', 'abritel', 'other'];

    /** États traduits ; tout autre code (`http_502`…) reste affiché tel quel. */
    public const STATUSES = ['ok', 'blocked', 'not_a_calendar'];

    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $label,
        public readonly string $provider,
        public readonly bool $active,
        public readonly ?string $lastSyncAt,
        public readonly string $lastStatus,
        public readonly int $lastEvents,
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
            (string) $row['provider'],
            (bool) $row['active'],
            $row['last_sync_at'] === null ? null : (string) $row['last_sync_at'],
            (string) $row['last_status'],
            (int) $row['last_events'],
        );
    }

    public function hasFailed(): bool
    {
        return $this->lastStatus !== '' && $this->lastStatus !== 'ok';
    }

    /**
     * Libellé de l'état de la dernière synchronisation.
     *
     * Les erreurs HTTP sont enregistrées avec leur code (`http_404`) pour que
     * le journal reste exploitable ; l'écran, lui, n'a que quatre libellés :
     * une clé de traduction par code inventerait un texte manquant.
     */
    public function statusLabelKey(): string
    {
        if ($this->lastStatus === '') {
            return 'calendar.import.never_synced';
        }

        return in_array($this->lastStatus, self::STATUSES, true)
            ? 'calendar.import.status.' . $this->lastStatus
            : 'calendar.import.status.unavailable';
    }

    public function providerLabelKey(): string
    {
        return 'calendar.import.provider.' . $this->provider;
    }
}
