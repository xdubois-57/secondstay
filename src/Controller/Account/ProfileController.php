<?php

declare(strict_types=1);

namespace SecondStay\Controller\Account;

use SecondStay\Auth\AccountService;
use SecondStay\Auth\ConsentRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\Role;
use SecondStay\Auth\WebAuthn\WebAuthnService;
use SecondStay\Controller\AbstractController;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;
use SecondStay\Notification\NotificationChannel;
use SecondStay\Notification\NotificationEvent;
use SecondStay\Notification\NotificationPreferenceRepository;
use SecondStay\Notification\NotificationService;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Security\Tokens;

/**
 * Espace client : profil, langue, mot de passe, appareils, passkeys, RGPD.
 */
final class ProfileController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $this->requireRole(Role::Customer);

        return $this->renderProfile($context, []);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateProfile(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        try {
            $this->container->get(AccountService::class)->updateProfile($user, [
                'first_name' => $context->request->input('first_name'),
                'last_name' => $context->request->input('last_name'),
                'phone' => $context->request->input('phone'),
                'locale' => $context->request->input('locale'),
            ]);
        } catch (ValidationException $exception) {
            return $this->renderProfile($context, $exception->errors(), 422);
        }

        $this->flashSuccess('account.profile.saved');

        $locale = Locales::normalise((string) $context->request->input('locale', $user->locale)) ?? $user->locale;

        return $this->redirectToRoute($context, 'account.profile', [], $locale);
    }

    /**
     * @param array<string, string> $params
     */
    public function changePassword(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $current = (string) $context->request->input('current_password', '');
        $new = (string) $context->request->input('new_password', '');
        $confirmation = (string) $context->request->input('new_password_confirm', '');

        if ($new !== $confirmation) {
            return $this->renderProfile($context, ['new_password' => 'account.error.password_mismatch'], 422);
        }

        $result = $this->container->get(AccountService::class)->changePassword(
            $user,
            $current,
            $new,
            Tokens::hash($this->session()->id()),
        );

        if ($result['ok'] === false) {
            return $this->renderProfile($context, ['current_password' => $result['error']], 422);
        }

        $this->flashSuccess('account.password.changed');

        return $this->redirectToRoute($context, 'account.profile');
    }

    /**
     * @param array<string, string> $params
     */
    public function revokeOtherSessions(RequestContext $context, array $params = []): Response
    {
        $this->requireRole(Role::Customer);
        $this->auth()->revokeOtherSessions();
        $this->flashSuccess('account.sessions.revoked');

        return $this->redirectToRoute($context, 'account.profile');
    }

    /**
     * Export RGPD au format JSON.
     *
     * @param array<string, string> $params
     */
    public function exportData(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);
        $data = $this->container->get(AccountService::class)->exportData($user);

        $this->audit()->record('account.exported', 'user', (string) $user->id, null, null, $user->id, $user->email);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new Response($json === false ? '{}' : $json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="secondstay-donnees-personnelles.json"',
            'Cache-Control' => 'private, no-store',
        ]));
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteAccount(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        // Un administrateur ne peut pas se supprimer par ce parcours : il doit
        // d'abord transmettre le rôle (AGENTS.md §7).
        if ($user->isAdministrator()) {
            $this->flashError('account.error.administrator_delete');

            return $this->redirectToRoute($context, 'account.profile');
        }

        $password = (string) $context->request->input('password', '');
        $hasher = $this->container->get(PasswordHasher::class);

        if ($user->passwordHash === null || !$hasher->verify($password, $user->passwordHash)) {
            return $this->renderProfile($context, ['delete_password' => 'account.error.current_password'], 422);
        }

        $this->container->get(AccountService::class)->anonymise($user);
        $this->auth()->logout();
        $this->session()->flash('success', 'account.delete.done');

        return $this->redirectToRoute($context, 'home');
    }

    /**
     * @param array<string, string> $params
     */
    public function deletePasskey(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $removed = $this->container->get(WebAuthnService::class)->deleteCredential($user, (int) ($params['id'] ?? 0));
        $removed ? $this->flashSuccess('account.passkey.removed') : $this->flashError('account.passkey.not_found');

        return $this->redirectToRoute($context, 'account.profile');
    }

    /**
     * Envoi de vérification vers les appareils du compte.
     *
     * C'est le seul moyen, pour le titulaire, de constater qu'un appareil
     * reçoit réellement les notifications sans attendre un événement réel.
     *
     * @param array<string, string> $params
     */
    public function sendTestNotification(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $result = $this->container->get(NotificationService::class)->notify(
            NotificationEvent::Test,
            $user,
            ['action_path' => '/' . $user->locale . '/account'],
            'test:' . $user->id,
        );

        if ($result['push'] === 0 && $result['attempted']['push']) {
            $this->flashWarning('notification.test_no_device');
        } else {
            $this->flashSuccess('notification.test_sent');
        }

        return $this->redirectToRoute($context, 'account.profile');
    }

    /**
     * Canaux de notification. L'e-mail reste obligatoire pour les messages
     * transactionnels (confirmation, réinitialisation) : la préférence ne
     * porte que sur les notifications d'événements.
     *
     * @param array<string, string> $params
     */
    public function saveNotificationPreferences(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);
        $preferences = $this->container->get(NotificationPreferenceRepository::class);

        foreach (NotificationChannel::cases() as $channel) {
            $preferences->set(
                $user->id,
                $channel,
                $context->request->input('channel_' . $channel->value) !== null
            );
        }

        $this->flashSuccess('notification.saved');

        return $this->redirectToRoute($context, 'account.profile');
    }

    /**
     * Les clés d'accès ne sont proposées que si le navigateur pourra les
     * accepter : une installation servie par adresse IP en est privée.
     */
    private function passkeysUsable(): bool
    {
        return $this->settings()->bool('account.allow_passkeys')
            && $this->container->get(WebAuthnService::class)->isAvailable();
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderProfile(RequestContext $context, array $errors, int $status = 200): Response
    {
        $user = $this->requireRole(Role::Customer);

        return $this->render('account/profile.html.twig', [
            'meta_title' => $this->trans('account.profile.title'),
            'profile' => [
                'email' => $user->email,
                'first_name' => $user->firstName,
                'last_name' => $user->lastName,
                'phone' => $user->phone,
                'locale' => $user->locale,
                'role' => $user->role->value,
            ],
            'locales' => Locales::ALL,
            'sessions' => $this->auth()->activeSessions(),
            'passkeys' => $this->container->get(WebAuthnService::class)->listCredentials($user),
            'passkeys_enabled' => $this->passkeysUsable(),
            'consents' => $this->container->get(ConsentRepository::class)->forUser($user->id),
            'bookings' => $this->container->get(BookingRepository::class)->forUser($user->id),
            'notification_preferences' => $this->container
                ->get(NotificationPreferenceRepository::class)
                ->forUser($user->id),
            'push_enabled' => $this->container->get(NotificationService::class)->isPushEnabled(),
            'push_devices' => count($this->container->get(PushSubscriptionRepository::class)->forUser($user->id)),
            'errors' => $errors,
            'min_password_length' => PasswordHasher::MIN_LENGTH,
        ], $status);
    }
}
