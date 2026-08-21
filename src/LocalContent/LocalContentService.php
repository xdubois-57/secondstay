<?php

declare(strict_types=1);

namespace SecondStay\LocalContent;

use DateTimeImmutable;
use DateTimeZone;
use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Http\HttpFetcher;
use SecondStay\I18n\Locales;
use SecondStay\Llm\LlmPrompt;
use SecondStay\Llm\LlmProvider;
use SecondStay\Logging\Logger;
use SecondStay\Pricing\DateRange;
use SecondStay\Settings\SettingsService;
use Throwable;

/**
 * Pipeline de contenu local (ARCHITECTURE.md §22).
 *
 * ```text
 * séjours à venir → récupération des URL → extraction → prompt gardé
 * → LlmProvider → validation du schéma → stockage → filtrage sur les dates
 * ```
 *
 * Trois règles gouvernent ce service :
 *
 * 1. **rien n'est inventé**. Une activité sans source, sans dates valides ou
 *    dont la source n'est pas une des URL consultées est écartée. Le modèle
 *    propose, la validation dispose ;
 * 2. **rien de personnel ne sort**. Le prompt ne contient ni nom, ni adresse,
 *    ni référence de séjour : un lieu, des dates, du texte public ;
 * 3. **le web est une donnée, pas une consigne**. Le contenu récupéré est
 *    enfermé entre marqueurs, et le prompt système le dit explicitement.
 */
final class LocalContentService
{
    /** Fenêtre par défaut avant l'arrivée, en semaines (SPECIFICATIONS.md §57). */
    public const DEFAULT_WINDOW_WEEKS = 5;

    /** Rafraîchissement par défaut, en jours. */
    public const DEFAULT_REFRESH_DAYS = 7;

    /** Nombre maximal de sources consultées par exécution. */
    public const MAX_SOURCES = 10;

    public function __construct(
        private readonly LocalContentRepository $repository,
        private readonly LlmProvider $provider,
        private readonly HttpFetcher $http,
        private readonly PageExtractor $extractor,
        private readonly PromptBuilder $prompts,
        private readonly BookingRepository $bookings,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->bool('llm.enabled') && $this->provider->isConfigured();
    }

    public function windowWeeks(): int
    {
        $weeks = $this->settings->int('llm.window_weeks');

        return $weeks > 0 ? $weeks : self::DEFAULT_WINDOW_WEEKS;
    }

    public function refreshDays(): int
    {
        $days = $this->settings->int('llm.refresh_days');

        return $days > 0 ? $days : self::DEFAULT_REFRESH_DAYS;
    }

    /**
     * Séjours entrés dans la fenêtre et dont le contenu doit être rafraîchi.
     *
     * @return list<Booking>
     */
    public function dueStays(?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $horizon = $this->shift($today, '+' . ($this->windowWeeks() * 7) . ' days');

        $due = [];
        foreach ($this->bookings->arrivingBetween($today, $horizon) as $booking) {
            if (!$booking->status->occupiesNights()) {
                continue;
            }

            if ($this->needsRefresh($booking, $today)) {
                $due[] = $booking;
            }
        }

        return $due;
    }

    /**
     * Rafraîchit tous les séjours arrivés dans la fenêtre.
     *
     * @return array{stays: int, items: int, failed: int}
     */
    public function refreshDue(?string $today = null): array
    {
        if (!$this->isEnabled()) {
            return ['stays' => 0, 'items' => 0, 'failed' => 0];
        }

        $stays = 0;
        $items = 0;
        $failed = 0;

        foreach ($this->dueStays($today) as $booking) {
            $result = $this->generateFor($booking, $booking->locale, $today);
            $stays++;

            if ($result['ok']) {
                $items += $result['items'];
                continue;
            }

            $failed++;
        }

        return ['stays' => $stays, 'items' => $items, 'failed' => $failed];
    }

    /**
     * Produit le contenu local d'un séjour.
     *
     * @return array{ok: bool, items: int, error: string, generation: int}
     */
    public function generateFor(Booking $booking, ?string $locale = null, ?string $today = null): array
    {
        return $this->generate($booking->range, $booking->id, $locale ?? $booking->locale, $today);
    }

    /**
     * Essai à blanc, depuis l'administration (SPECIFICATIONS.md §56).
     *
     * Le propriétaire doit pouvoir vérifier sa configuration sans attendre un
     * séjour : l'essai porte sur une fenêtre proche et n'est rattaché à aucune
     * réservation.
     *
     * @return array{ok: bool, items: int, error: string, generation: int}
     */
    public function test(string $locale, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $range = DateRange::fromStrings($today, $this->shift($today, '+7 days'));

        return $this->generate($range, null, $locale, $today);
    }

    /**
     * Activités d'un séjour, limitées à ses dates exactes
     * (SPECIFICATIONS.md §58).
     *
     * @return array{book_ahead: list<LocalActivity>, this_week: list<LocalActivity>}
     */
    public function activitiesFor(Booking $booking, string $locale): array
    {
        $locale = Locales::isSupported($locale) ? $locale : $booking->locale;

        $activities = $this->repository->activitiesFor(
            $booking->id,
            $locale,
            $booking->range->arrival->format('Y-m-d'),
            $booking->range->departure->format('Y-m-d'),
        );

        $bookAhead = [];
        $thisWeek = [];

        foreach ($activities as $activity) {
            if ($activity->bookingRequired) {
                $bookAhead[] = $activity;
                continue;
            }

            $thisWeek[] = $activity;
        }

        return ['book_ahead' => $bookAhead, 'this_week' => $thisWeek];
    }

    // --- Pipeline -----------------------------------------------------------------------

    /**
     * @return array{ok: bool, items: int, error: string, generation: int}
     */
    private function generate(DateRange $range, ?int $bookingId, string $locale, ?string $today): array
    {
        $today ??= gmdate('Y-m-d');
        $locale = Locales::isSupported($locale) ? $locale : Locales::FALLBACK;

        $generationId = $this->repository->startGeneration([
            'booking_id' => $bookingId,
            'locale' => $locale,
            'status' => 'running',
            'provider' => $this->provider->name(),
            'range_start' => $range->arrival->format('Y-m-d'),
            'range_end' => $range->departure->format('Y-m-d'),
        ]);

        if (!$this->isEnabled()) {
            return $this->fail($generationId, 'llm.error.disabled');
        }

        $documents = $this->collect();
        if ($documents === []) {
            return $this->fail($generationId, 'llm.error.no_source');
        }

        $prompt = new LlmPrompt(
            $this->prompts->system($locale),
            $this->prompts->user($range, $locale, $documents, $today),
            ActivitySchema::jsonSchema(),
        );

        $result = $this->provider->complete($prompt);
        if (!$result->ok) {
            return $this->fail($generationId, $result->error, count($documents));
        }

        $allowed = [];
        foreach ($documents as $document) {
            $allowed[$document->url] = true;
        }

        $activities = $this->validate($result->data, $allowed, $today);

        $this->repository->replaceActivities($generationId, $bookingId, $locale, $activities);

        $this->repository->finishGeneration($generationId, [
            'status' => 'done',
            'model' => mb_substr($result->model, 0, 64),
            'sources' => count($documents),
            'items' => count($activities),
            'error' => '',
        ]);

        $this->logger->info('local', 'Contenu local produit', [
            'booking' => $bookingId,
            'locale' => $locale,
            'items' => count($activities),
            'sources' => count($documents),
        ]);

        $this->audit?->record('local.generated', 'local_generation', (string) $generationId, null, [
            'booking' => $bookingId,
            'items' => count($activities),
        ]);

        return ['ok' => true, 'items' => count($activities), 'error' => '', 'generation' => $generationId];
    }

    /**
     * Récupère et réduit les sources actives.
     *
     * @return list<SourceDocument>
     */
    private function collect(): array
    {
        $documents = [];

        foreach ($this->repository->sources(true) as $source) {
            if (count($documents) >= self::MAX_SOURCES) {
                break;
            }

            try {
                // Toute sortie passe par `HttpFetcher`, donc par le garde
                // SSRF : une URL qui pointe vers le réseau interne lève ici.
                $response = $this->http->get($source->url, ['Accept' => 'text/html,text/plain']);
            } catch (Throwable) {
                $this->repository->recordFetch($source->id, 'blocked');
                continue;
            }

            if ($response['status'] !== 200) {
                $this->repository->recordFetch($source->id, 'http_' . $response['status']);
                continue;
            }

            $text = $this->extractor->extract($response['body']);
            if ($text === '') {
                $this->repository->recordFetch($source->id, 'empty');
                continue;
            }

            $this->repository->recordFetch($source->id, 'ok');
            $documents[] = new SourceDocument($source->id, $source->url, $source->label, $text);
        }

        return $documents;
    }

    /**
     * Ne garde que ce qui est utilisable.
     *
     * @param array<string, mixed> $data
     * @param array<string, bool>  $allowed URL réellement consultées
     *
     * @return list<array<string, mixed>>
     */
    private function validate(array $data, array $allowed, string $today): array
    {
        /** @var list<mixed> $raw */
        $raw = is_array($data['activities'] ?? null) ? array_values($data['activities']) : [];

        $activities = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $start = $this->date((string) ($item['starts_on'] ?? ''));
            $end = $this->date((string) ($item['ends_on'] ?? ''));
            $source = (string) ($item['source_url'] ?? '');

            if ($title === '' || $start === null || $end === null || $end < $start) {
                continue;
            }

            // Une source que l'on n'a pas consultée est une source inventée.
            if (!isset($allowed[$source])) {
                continue;
            }

            $category = (string) ($item['category'] ?? 'other');

            $activities[] = [
                'title' => mb_substr($title, 0, 190),
                'summary' => mb_substr(trim((string) ($item['summary'] ?? '')), 0, 4000),
                'category' => in_array($category, ActivitySchema::CATEGORIES, true) ? $category : 'other',
                'starts_on' => $start,
                'ends_on' => $end,
                'booking_required' => ($item['booking_required'] ?? false) === true ? 1 : 0,
                'location' => mb_substr(trim((string) ($item['location'] ?? '')), 0, 190),
                'source_url' => mb_substr($source, 0, 500),
                // La date de vérification est celle de la consultation, pas
                // celle que le modèle voudrait bien annoncer.
                'verified_on' => $today,
            ];

            if (count($activities) >= ActivitySchema::MAX_ITEMS) {
                break;
            }
        }

        return $activities;
    }

    /**
     * @return array{ok: false, items: int, error: string, generation: int}
     */
    private function fail(int $generationId, string $error, int $sources = 0): array
    {
        $this->repository->finishGeneration($generationId, [
            'status' => 'failed',
            'sources' => $sources,
            'items' => 0,
            'error' => mb_substr($error, 0, 190),
        ]);

        return ['ok' => false, 'items' => 0, 'error' => $error, 'generation' => $generationId];
    }

    /**
     * Le contenu de ce séjour doit-il être régénéré ?
     */
    private function needsRefresh(Booking $booking, string $today): bool
    {
        $last = $this->repository->lastGeneration($booking->id);
        if ($last === null) {
            return true;
        }

        $threshold = $this->shift($today . ' 00:00:00', '-' . $this->refreshDays() . ' days', 'Y-m-d H:i:s');

        return (string) $last['created_at'] < $threshold;
    }

    private function date(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new DateTimeZone('UTC'));

        return $date === false ? null : $date->format('Y-m-d');
    }

    private function shift(string $from, string $modifier, string $format = 'Y-m-d'): string
    {
        $base = str_contains($from, ':') ? $from : $from . ' 00:00:00';

        return (new DateTimeImmutable($base, new DateTimeZone('UTC')))->modify($modifier)->format($format);
    }
}
