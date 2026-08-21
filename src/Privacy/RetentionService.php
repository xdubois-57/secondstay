<?php

declare(strict_types=1);

namespace SecondStay\Privacy;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\SessionRepository;
use SecondStay\Auth\TokenRepository;
use SecondStay\Booking\WaitlistRepository;
use SecondStay\Logging\Logger;
use SecondStay\Notification\NotificationRepository;
use SecondStay\Payment\WebhookRepository;
use SecondStay\Police\PoliceRecordService;
use SecondStay\Security\RateLimiter;
use SecondStay\Settings\SettingsService;
use SecondStay\Stay\GuestLinkRepository;

/**
 * Rétention et purge des données (SPECIFICATIONS.md §65).
 *
 * Le RGPD ne demande pas seulement de protéger les données : il demande de
 * **ne pas les garder** au-delà de ce qui les justifie. Ce service applique
 * les durées configurées, en un seul endroit, pour que personne n'ait à se
 * souvenir de purger telle table.
 *
 * Ce qui n'est **pas** purgé ici mérite d'être dit : les séjours, les
 * paiements, les contrats acceptés et les états des lieux sont des pièces
 * comptables et contractuelles. Les effacer automatiquement priverait les deux
 * parties de leur preuve ; leur suppression reste une décision humaine.
 */
final class RetentionService
{
    /** Durée de conservation par défaut des jetons de lien invité, en jours. */
    public const GUEST_LINK_DAYS = 30;

    /** Notifications de fournisseur de paiement : ce sont des reçus techniques. */
    public const WEBHOOK_DAYS = 365;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Logger $logger,
        private readonly NotificationRepository $notifications,
        private readonly SessionRepository $sessions,
        private readonly TokenRepository $tokens,
        private readonly GuestLinkRepository $guestLinks,
        private readonly WaitlistRepository $waitlist,
        private readonly WebhookRepository $webhooks,
        private readonly RateLimiter $rateLimiter,
        private readonly PoliceRecordService $police,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Durées appliquées, telles qu'elles peuvent être affichées.
     *
     * @return array<string, int> catégorie => jours
     */
    public function policy(): array
    {
        return [
            'logs' => max(1, $this->settings->int('logging.retention_days')),
            'notifications' => max(1, $this->settings->int('notification.retention_days')),
            'guest_links' => self::GUEST_LINK_DAYS,
            'webhooks' => self::WEBHOOK_DAYS,
            'police_records' => $this->police->retentionDays(),
        ];
    }

    /**
     * Applique toutes les rétentions et rend le détail de ce qui a disparu.
     *
     * @return array<string, int> catégorie => lignes supprimées
     */
    public function purge(?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $policy = $this->policy();

        $removed = [
            'logs' => $this->logger->purgeOlderThan($policy['logs']),
            'notifications' => $this->notifications->purgeOlderThan($policy['notifications']),
            'sessions' => $this->sessions->purgeExpired(),
            'tokens' => $this->tokens->purgeExpired(),
            'guest_links' => $this->guestLinks->purgeExpiredBefore(
                $this->daysBefore($today, $policy['guest_links']) . ' 00:00:00'
            ),
            'waitlist' => $this->waitlist->purgeBefore($today),
            'webhooks' => $this->webhooks->purgeBefore(
                $this->daysBefore($today, $policy['webhooks']) . ' 00:00:00'
            ),
            'rate_limits' => $this->rateLimiter->purge(),
            'police_records' => $this->police->purge($today),
        ];

        $total = array_sum($removed);
        if ($total > 0) {
            $this->logger->info('privacy', 'Rétention appliquée', $removed);
            // La purge elle-même est auditée : effacer sans trace serait un
            // trou dans la piste d'audit.
            $this->audit?->record('privacy.purged', 'retention', 'all', null, $removed);
        }

        return $removed;
    }

    private function daysBefore(string $today, int $days): string
    {
        return (new \DateTimeImmutable($today . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify('-' . max(1, $days) . ' days')
            ->format('Y-m-d');
    }
}
