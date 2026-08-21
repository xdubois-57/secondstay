<?php

declare(strict_types=1);

namespace SecondStay\Calendar;

use SecondStay\Audit\AuditTrail;
use SecondStay\Availability\AvailabilityBlockRepository;
use SecondStay\Http\HttpFetcher;
use SecondStay\Logging\Logger;
use Throwable;

/**
 * Import des calendriers externes (SPECIFICATIONS.md §52).
 *
 * Trois règles :
 *
 * 1. **les événements bloquent, ils ne réservent pas.** Un flux distant ne
 *    crée jamais de séjour : il pose des indisponibilités, avec leur
 *    provenance. Confondre les deux ferait apparaître des clients qui
 *    n'existent pas ;
 * 2. **une synchronisation ne touche que ses propres lignes.** Ce que le
 *    propriétaire a bloqué à la main survit à n'importe quel import ;
 * 3. **un flux muet ne libère rien.** Une erreur réseau laisse les blocages en
 *    place : rendre disponibles des nuits déjà vendues ailleurs serait le pire
 *    résultat possible.
 */
final class ExternalCalendarService
{
    public function __construct(
        private readonly ExternalCalendarRepository $calendars,
        private readonly AvailabilityBlockRepository $blocks,
        private readonly IcsParser $parser,
        private readonly HttpFetcher $http,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Synchronise un flux.
     *
     * @return array{ok: bool, events: int, error: string}
     */
    public function sync(ExternalCalendar $calendar): array
    {
        if (!$calendar->active) {
            return ['ok' => false, 'events' => 0, 'error' => 'calendar.import.error.inactive'];
        }

        try {
            // Toute sortie passe par le garde SSRF : une URL de flux qui
            // pointe vers le réseau interne lève ici.
            $response = $this->http->get($calendar->url, ['Accept' => 'text/calendar,text/plain']);
        } catch (Throwable) {
            return $this->fail($calendar, 'blocked');
        }

        if ($response['status'] !== 200) {
            return $this->fail($calendar, 'http_' . $response['status']);
        }

        $events = $this->parser->parse($response['body']);
        if ($events === [] && !$this->looksLikeCalendar($response['body'])) {
            // Une page HTML renvoyée à la place d'un flux : mieux vaut le dire
            // que d'effacer tous les blocages de la source.
            return $this->fail($calendar, 'not_a_calendar');
        }

        $written = $this->blocks->replaceForSource($calendar->id, $events);

        $this->calendars->recordSync($calendar->id, 'ok', $written);

        $this->logger->info('calendar', 'Flux externe synchronisé', [
            'calendar' => $calendar->id,
            'provider' => $calendar->provider,
            'events' => $written,
        ]);

        $this->audit?->record('calendar.imported', 'external_calendar', (string) $calendar->id, null, [
            'events' => $written,
        ]);

        return ['ok' => true, 'events' => $written, 'error' => ''];
    }

    /**
     * Synchronise tous les flux actifs.
     *
     * @return array{calendars: int, events: int, failed: int}
     */
    public function syncAll(): array
    {
        $calendars = 0;
        $events = 0;
        $failed = 0;

        foreach ($this->calendars->all(true) as $calendar) {
            $result = $this->sync($calendar);
            $calendars++;

            if ($result['ok']) {
                $events += $result['events'];
                continue;
            }

            $failed++;
        }

        return ['calendars' => $calendars, 'events' => $events, 'failed' => $failed];
    }

    /**
     * Le corps ressemble-t-il à un calendrier ?
     */
    private function looksLikeCalendar(string $body): bool
    {
        return stripos($body, 'BEGIN:VCALENDAR') !== false;
    }

    /**
     * @return array{ok: false, events: int, error: string}
     */
    private function fail(ExternalCalendar $calendar, string $status): array
    {
        $this->calendars->recordSync($calendar->id, $status, $calendar->lastEvents);

        $this->logger->warning('calendar', 'Flux externe indisponible', [
            'calendar' => $calendar->id,
            'status' => $status,
        ]);

        // Le code HTTP est conservé dans le journal, mais l'écran ne peut
        // afficher qu'un message existant : inventer une clé par code
        // produirait un libellé manquant dans les quatre langues.
        $key = in_array($status, ['blocked', 'not_a_calendar'], true) ? $status : 'unavailable';

        return ['ok' => false, 'events' => 0, 'error' => 'calendar.import.error.' . $key];
    }
}
