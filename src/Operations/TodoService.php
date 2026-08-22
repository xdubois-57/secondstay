<?php

declare(strict_types=1);

namespace SecondStay\Operations;

use SecondStay\Backup\BackupService;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingStatus;
use SecondStay\Compliance\ComplianceService;
use SecondStay\Dispute\DisputeRepository;
use SecondStay\Database\Migrator;
use SecondStay\Diagnostics\OperationsDiagnostics;
use SecondStay\Imap\InboundMailRepository;
use SecondStay\Incident\IncidentRepository;
use SecondStay\Logging\LogLevel;
use SecondStay\Logging\LogRepository;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Payment\PaymentRepository;
use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\TaskStateRepository;
use SecondStay\Settings\SettingsService;
use Throwable;

/**
 * Tableau « À faire » (SPECIFICATIONS.md §50).
 *
 * Il ne liste que ce qui **réclame une décision humaine** : une demande à
 * valider, une échéance dépassée, une caution à restituer, un contrat qui
 * n'est pas signé, un courrier qu'aucune règle n'a su rattacher, une
 * sauvegarde absente, une erreur récente, une mise à jour disponible, une
 * migration en attente. Un tableau qui listerait tout ce qui existe ne serait
 * plus lu.
 *
 * Aucune entrée ne coûte un appel sortant. Le tableau s'affiche sur deux
 * écrans très fréquentés : le rendre dépendant du réseau le rendrait aussi
 * lent et aussi fragile que lui.
 */
final class TodoService
{
    /** Horizon des séjours à préparer, en jours. */
    public const HORIZON_DAYS = 14;

    /** Fenêtre des erreurs récentes, en heures. */
    public const ERROR_WINDOW_HOURS = 24;

    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly PaymentRepository $payments,
        private readonly InboundMailRepository $mails,
        private readonly ChecklistService $checklists,
        private readonly ?Migrator $migrator = null,
        private readonly ?IncidentRepository $incidents = null,
        private readonly ?ComplianceService $compliance = null,
        private readonly ?DisputeRepository $disputes = null,
        private readonly ?BackupService $backups = null,
        private readonly ?LogRepository $logs = null,
        private readonly ?TaskStateRepository $tasks = null,
        private readonly ?MaintenanceMode $maintenance = null,
        private readonly ?SettingsService $settings = null,
    ) {
    }

    /**
     * Le `count` d'une entrée est un **nombre d'éléments à traiter**, jamais
     * une autre grandeur : il s'affiche tel quel dans une pastille à côté du
     * libellé, et un âge ou un pourcentage s'y lirait comme une quantité.
     *
     * @return list<array{code: string, key: string, severity: string, count: int, route: string, params: array<string, string|int>}>
     */
    public function items(?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $items = [];

        $toConfirm = $this->bookings->listing([BookingStatus::ToConfirm, BookingStatus::Request]);
        if ($toConfirm !== []) {
            $items[] = $this->item('bookings_to_confirm', 'warning', count($toConfirm), 'admin.bookings');
        }

        $overdue = $this->payments->overdue($today);
        if ($overdue !== []) {
            $items[] = $this->item('payments_overdue', 'danger', count($overdue), 'admin.payments');
        }

        $held = array_values(array_filter(
            $this->payments->heldDeposits(),
            static fn (array $row): bool => $row['payment']->holdStatus->value === 'to_return'
        ));
        if ($held !== []) {
            $items[] = $this->item('deposits_to_return', 'warning', count($held), 'admin.payments');
        }

        // Le décompte porte sur la table entière, jamais sur une page : une
        // pastille plafonnée annoncerait « 50 » à une boîte qui en compte deux
        // cents, et le propriétaire croirait avoir fini bien avant la fin.
        $unlinked = $this->mails->countUnlinked();
        if ($unlinked > 0) {
            $items[] = $this->item('mail_unlinked', 'info', $unlinked, 'admin.mailbox');
        }

        $unprepared = $this->unpreparedStays($today);
        if ($unprepared !== []) {
            $items[] = $this->item('stays_to_prepare', 'warning', count($unprepared), 'admin.operations');
        }

        // Un incident ouvert réclame une décision : c'est exactement ce que
        // ce tableau doit montrer (SPECIFICATIONS.md §50).
        $openIncidents = $this->incidents?->countOpen() ?? 0;
        if ($openIncidents > 0) {
            $items[] = $this->item('incidents_open', 'danger', $openIncidents, 'admin.incidents');
        }

        // Conformité : un sujet à vérifier ou dont la revue est dépassée
        // réclame une décision, exactement comme un paiement en retard
        // (SPECIFICATIONS.md §50).
        $compliance = $this->compliance?->outstanding($today) ?? [];
        if ($compliance !== []) {
            $items[] = $this->item('compliance_to_verify', 'warning', count($compliance), 'admin.compliance');
        }

        // Un litige ouvert attend une décision : caution retenue ou rendue.
        $openDisputes = $this->disputes?->countOpen() ?? 0;
        if ($openDisputes > 0) {
            $items[] = $this->item('disputes_open', 'danger', $openDisputes, 'admin.disputes');
        }

        // Un séjour confirmé sans contrat signé engage les deux parties sans
        // que rien ne dise sur quoi : c'est une décision à prendre, pas une
        // formalité (SPECIFICATIONS.md §39 et §50).
        $pendingContracts = $this->bookings->countPendingContracts($today);
        if ($pendingContracts > 0) {
            $items[] = $this->item('contracts_pending', 'warning', $pendingContracts, 'admin.bookings');
        }

        $backup = $this->backupItem();
        if ($backup !== null) {
            $items[] = $backup;
        }

        $errors = $this->recentErrors();
        if ($errors > 0) {
            $items[] = $this->item('errors_recent', 'danger', $errors, 'admin.logs');
        }

        if ($this->updateAvailable()) {
            $items[] = $this->item('update_available', 'info', 1, 'admin.updates');
        }

        // Le site fermé et le logement sans nom sont deux états que rien
        // d'autre ne rappelle : le premier coûte des réservations chaque
        // jour, le second se voit sur chaque page publique.
        if ($this->maintenance?->isActive() === true) {
            $items[] = $this->item('maintenance_active', 'warning', 1, 'admin.dashboard');
        }

        if ($this->settings !== null && $this->settings->string('property.name') === '') {
            $items[] = $this->item('property_name', 'info', 1, 'admin.settings');
        }

        // Appelé une seule fois : `pending()` lit le disque **et** la base, et
        // ce tableau s'affiche sur les deux écrans les plus fréquentés.
        $pendingMigrations = $this->migrator?->pending() ?? [];
        if ($pendingMigrations !== []) {
            $items[] = $this->item('migrations_pending', 'danger', count($pendingMigrations), 'admin.diagnostics');
        }

        return $items;
    }

    /**
     * Absence ou vieillissement des sauvegardes.
     *
     * Les deux cas méritent le même endroit mais pas la même gravité :
     * n'avoir aucune sauvegarde est une bombe à retardement, en avoir une trop
     * ancienne est une perte de données bornée.
     *
     * @return array{code: string, key: string, severity: string, count: int, route: string, params: array<string, string|int>}|null
     */
    private function backupItem(): ?array
    {
        if ($this->backups === null) {
            return null;
        }

        try {
            $backups = $this->backups->list();
        } catch (Throwable) {
            return null;
        }

        if ($backups === []) {
            return $this->item('backup_missing', 'danger', 1, 'admin.backups');
        }

        $newest = strtotime($backups[0]['created_at']);
        $ageDays = $newest === false ? PHP_INT_MAX : intdiv(max(0, time() - $newest), 86400);

        // Le compte est un nombre de choses à traiter, pas un âge : afficher
        // « 30 » à côté de « sauvegarde trop ancienne » se lirait comme trente
        // sauvegardes en retard.
        return $ageDays > OperationsDiagnostics::BACKUP_MAX_AGE_DAYS
            ? $this->item('backup_stale', 'warning', 1, 'admin.backups')
            : null;
    }

    /**
     * Erreurs et pannes critiques des dernières vingt-quatre heures.
     */
    private function recentErrors(): int
    {
        if ($this->logs === null) {
            return 0;
        }

        try {
            return $this->logs->countAtLeast(
                LogLevel::Error,
                gmdate('Y-m-d H:i:s', time() - self::ERROR_WINDOW_HOURS * 3600)
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Une mise à jour est-elle disponible ?
     *
     * La réponse est lue dans le résultat de la tâche périodique, jamais
     * demandée à GitHub : construire ce tableau ne doit pas dépendre d'un
     * appel sortant, sous peine de rendre l'écran d'exploitation aussi lent
     * et aussi fragile que le réseau.
     */
    private function updateAvailable(): bool
    {
        if ($this->tasks === null) {
            return false;
        }

        try {
            return $this->tasks->state(ScheduledTask::UpdateCheck)->lastDetail
                === 'scheduler.detail.update_available';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Séjours proches dont la préparation n'est pas terminée.
     *
     * @return list<array{booking: Booking, outstanding: list<ChecklistItem>}>
     */
    public function unpreparedStays(?string $today = null, int $horizonDays = self::HORIZON_DAYS): array
    {
        $today ??= gmdate('Y-m-d');
        $limit = (new \DateTimeImmutable($today . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify('+' . max(1, $horizonDays) . ' days')
            ->format('Y-m-d');

        $stays = [];

        foreach ($this->bookings->arrivingBetween($today, $limit) as $booking) {
            $outstanding = array_values(array_filter(
                $this->checklists->before($booking),
                static fn (ChecklistItem $item): bool => $item->needsAction()
            ));

            if ($outstanding !== []) {
                $stays[] = ['booking' => $booking, 'outstanding' => $outstanding];
            }
        }

        return $stays;
    }

    /**
     * @param array<string, string|int> $params
     *
     * @return array{code: string, key: string, severity: string, count: int, route: string, params: array<string, string|int>}
     */
    private function item(string $code, string $severity, int $count, string $route, array $params = []): array
    {
        return [
            'code' => $code,
            // Le tableau de bord et la page d'exploitation affichent la même
            // liste : une seule clé de traduction, une seule vérité.
            'key' => 'operations.todo.' . $code,
            'severity' => $severity,
            'count' => $count,
            'route' => $route,
            'params' => $params,
        ];
    }
}
