<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Auth\Role;
use SecondStay\Auth\UserRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Calendar\CalendarScope;
use SecondStay\Calendar\CalendarService;
use SecondStay\Calendar\CalendarTokenRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Calendar\ExternalCalendar;
use SecondStay\Calendar\ExternalCalendarRepository;
use SecondStay\Calendar\ExternalCalendarService;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Http\UrlGuard;
use SecondStay\Operations\ChecklistService;
use SecondStay\Operations\TodoService;
use SecondStay\Scheduler\ScheduledTask;
use SecondStay\Scheduler\SchedulerFactory;
use SecondStay\Scheduler\TaskState;
use SecondStay\Scheduler\TaskStateRepository;

/**
 * Exploitation : « À faire », séjours à préparer, affectation du responsable,
 * checklists et calendriers privés (SPECIFICATIONS.md §48 à §51).
 */
final class AdminOperationsController extends AdminController
{
    protected function section(): string
    {
        return 'operations';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $todo = $this->container->get(TodoService::class);
        $checklists = $this->container->get(ChecklistService::class);
        $bookings = $this->container->get(BookingRepository::class);

        // Un responsable local voit ses propres séjours ; un administrateur
        // voit toute la préparation.
        $mine = $user->role === Role::LocalManager
            ? $bookings->forManager($user->id)
            : [];

        $issued = $this->takeIssuedToken();

        return $this->renderAdmin('admin/operations.html.twig', [
            'meta_title' => $this->trans('operations.title'),
            'todo' => $user->role === Role::LocalManager ? [] : $todo->items(),
            'stays' => $todo->unpreparedStays(null, max(1, $this->settings()->int('operations.prepare_days'))),
            'my_stays' => $mine,
            'is_manager_only' => $user->role === Role::LocalManager,
            'tokens' => $this->container->get(CalendarTokenRepository::class)->all(),
            'issued_token' => $issued,
            'issued_url' => $issued === '' ? '' : $this->feedUrl($context, $issued),
            'scopes' => [CalendarScope::Admin, CalendarScope::Manager],
            'progress' => array_map(
                static fn (array $row): array => $checklists->progress($row['booking']),
                $todo->unpreparedStays(null, max(1, $this->settings()->int('operations.prepare_days')))
            ),
            'imports' => $this->container->get(ExternalCalendarRepository::class)->all(),
            'providers' => ExternalCalendar::PROVIDERS,
            // L'état du planificateur se lit ici parce que c'est ici qu'on
            // regarde quand quelque chose n'est pas arrivé : le courrier qui
            // n'est pas relevé, la sauvegarde qui manque.
            'tasks' => $user->role === Role::LocalManager ? [] : $this->taskStates(),
        ]);
    }

    /**
     * Adresse complète du flux : c'est elle que l'on colle dans un agenda,
     * pas un chemin relatif.
     */
    private function feedUrl(RequestContext $context, string $token): string
    {
        $base = rtrim($this->settings()->string('site.public_url'), '/');
        if ($base === '') {
            $base = rtrim($context->request->baseUrl(), '/');
        }

        return $base . $this->router()->path('calendar.feed', ['token' => $token]);
    }

    /**
     * Récupère le jeton fraîchement délivré, puis l'oublie.
     *
     * Il ne doit être affiché qu'une fois : le laisser en session le
     * réafficherait à chaque visite de la page.
     */
    private function takeIssuedToken(): string
    {
        $token = $this->session()->string('calendar_token');
        if ($token !== '') {
            $this->session()->remove('calendar_token');
        }

        return $token;
    }

    /**
     * Affecte un responsable local à un séjour (SPECIFICATIONS.md §48).
     *
     * @param array<string, string> $params
     */
    public function assignManager(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $bookings = $this->container->get(BookingRepository::class);
        $booking = $bookings->find((int) ($params['id'] ?? 0));
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        $managerId = (int) $context->request->input('manager_id', '0');

        if ($managerId !== 0) {
            $manager = $this->container->get(UserRepository::class)->findById($managerId);

            // Seul un compte réellement opérationnel peut être responsable :
            // affecter un client lui donnerait une visibilité qu'il n'a pas.
            if ($manager === null || !$manager->isOperational()) {
                $this->flashError('operations.error.manager_invalid');

                return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
            }
        }

        $bookings->update($booking->id, ['manager_id' => $managerId === 0 ? null : $managerId]);

        $this->audit()->record('booking.manager_assigned', 'booking', (string) $booking->id, [
            'manager_id' => $booking->managerId,
        ], ['manager_id' => $managerId === 0 ? null : $managerId], $user->id, $user->email);

        $this->flashSuccess('operations.manager.assigned');

        return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
    }

    /**
     * Coche ou décoche une ligne de checklist.
     *
     * @param array<string, string> $params
     */
    public function toggleTask(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $booking = $this->container->get(BookingRepository::class)->find((int) ($params['id'] ?? 0));
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        $result = $this->container->get(ChecklistService::class)->toggle(
            $booking,
            (string) $context->request->input('code', ''),
            $context->request->input('done') !== null,
            $user->id,
            (string) $context->request->input('note', ''),
        );

        $result['ok'] ? $this->flashSuccess('operations.checklist.updated') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
    }

    /**
     * Délivre un lien de calendrier.
     *
     * @param array<string, string> $params
     */
    public function issueCalendar(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $scope = CalendarScope::fromString((string) $context->request->input('scope', ''));
        if ($scope === CalendarScope::Customer) {
            // Le flux d'un voyageur se délivre depuis son espace, jamais ici.
            $this->flashError('calendar.error.not_found');

            return $this->redirectToRoute($context, 'admin.operations');
        }

        if ($scope === CalendarScope::Admin && !$user->role->isAdministrator()) {
            throw new NotFoundException('Portée non autorisée.');
        }

        $token = $this->container->get(CalendarService::class)->tokenFor($scope, $user);

        // Le jeton n'est montré qu'une fois : il transite par un message
        // éphémère plutôt que par l'URL, où il resterait dans l'historique.
        $this->session()->set('calendar_token', $token);
        $this->flashSuccess('calendar.created');

        $this->audit()->record('calendar.token_issued', 'calendar_token', $scope->value, null, [
            'scope' => $scope->value,
        ], $user->id, $user->email);

        return $this->redirectToRoute($context, 'admin.operations');
    }

    /**
     * @param array<string, string> $params
     */
    public function revokeCalendar(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $tokens = $this->container->get(CalendarTokenRepository::class);
        $token = $tokens->find((int) ($params['id'] ?? 0));

        if ($token === null || $token->isRevoked()) {
            $this->flashError('calendar.error.not_found');

            return $this->redirectToRoute($context, 'admin.operations');
        }

        // Un responsable local ne révoque que ses propres liens.
        if (!$user->role->isAdministrator() && $token->userId !== $user->id) {
            throw new NotFoundException('Lien introuvable.');
        }

        $tokens->revoke($token->id);

        $this->audit()->record('calendar.token_revoked', 'calendar_token', (string) $token->id, [
            'scope' => $token->scope->value,
        ], null, $user->id, $user->email);

        $this->flashSuccess('calendar.revoked');

        return $this->redirectToRoute($context, 'admin.operations');
    }

    /**
     * Déclare un calendrier externe à importer (SPECIFICATIONS.md §52).
     *
     * @param array<string, string> $params
     */
    public function addCalendarImport(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $url = trim((string) $context->request->input('url', ''));

        // Même règle que pour les sources de contenu local : ce qui est
        // certainement interdit est refusé tout de suite, le reste est
        // contrôlé à chaque requête sortante.
        $inspection = (new UrlGuard())->inspect($url);
        if ($inspection['ok'] === false && $inspection['reason'] !== 'ssrf.dns_failed') {
            $this->flashError($inspection['reason']);

            return $this->redirectToRoute($context, 'admin.operations');
        }

        $calendars = $this->container->get(ExternalCalendarRepository::class);
        if ($calendars->findByUrl($url) !== null) {
            $this->flashWarning('calendar.import.error.duplicate');

            return $this->redirectToRoute($context, 'admin.operations');
        }

        $calendars->create(
            $url,
            (string) $context->request->input('label', ''),
            (string) $context->request->input('provider', 'other'),
        );

        $this->audit()->record(
            'calendar.import_added',
            'external_calendar',
            $url,
            null,
            null,
            $user->id,
            $user->email
        );
        $this->flashSuccess('calendar.import.added');

        return $this->redirectToRoute($context, 'admin.operations');
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteCalendarImport(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $calendars = $this->container->get(ExternalCalendarRepository::class);
        $calendar = $calendars->find((int) ($params['id'] ?? 0));
        if ($calendar === null) {
            throw new NotFoundException('Calendrier introuvable.');
        }

        $calendars->delete($calendar->id);

        $this->audit()->record(
            'calendar.import_deleted',
            'external_calendar',
            $calendar->url,
            null,
            null,
            $user->id,
            $user->email
        );
        $this->flashSuccess('calendar.import.deleted');

        return $this->redirectToRoute($context, 'admin.operations');
    }

    /**
     * État des tâches périodiques, prêt pour l'affichage.
     *
     * @return list<array<string, mixed>>
     */
    private function taskStates(): array
    {
        $now = gmdate('Y-m-d H:i:s');

        return array_map(
            static fn (TaskState $state): array => $state->toArray() + ['stale' => $state->isStale($now)],
            $this->container->get(TaskStateRepository::class)->all()
        );
    }

    /**
     * Exécute une tâche périodique à la demande.
     *
     * Un propriétaire doit pouvoir relever son courrier ou lancer sa
     * sauvegarde sans attendre le prochain passage du cron — et doit surtout
     * pouvoir vérifier qu'une tâche fonctionne avant de compter dessus.
     *
     * @param array<string, string> $params
     */
    public function runTask(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $task = ScheduledTask::tryFromCode($context->request->input('task', '') ?? '');
        if ($task === null) {
            throw new NotFoundException('Tâche inconnue.');
        }

        $result = SchedulerFactory::build($this->container)->runNow($task);

        $this->audit()->record(
            'scheduler.run',
            'scheduled_task',
            $task->value,
            null,
            ['status' => $result['status']],
            $user->id,
            $user->email
        );

        if ($result['status'] === 'error') {
            $this->flashWarning('scheduler.flash.failed');
        } elseif ($result['status'] === 'skipped') {
            $this->flashWarning('scheduler.flash.skipped');
        } else {
            $this->flashSuccess('scheduler.flash.done');
        }

        return $this->redirectToRoute($context, 'admin.operations');
    }

    /**
     * Synchronise les calendriers externes, à la demande.
     *
     * @param array<string, string> $params
     */
    public function syncCalendarImports(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $result = $this->container->get(ExternalCalendarService::class)->syncAll();

        if ($result['calendars'] === 0) {
            $this->flashWarning('calendar.import.nothing');
        } elseif ($result['failed'] > 0) {
            $this->flashWarning('calendar.import.partial');
        } else {
            $this->flashSuccess('calendar.import.synced');
        }

        return $this->redirectToRoute($context, 'admin.operations');
    }
}
