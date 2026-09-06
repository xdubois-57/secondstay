<?php

declare(strict_types=1);

namespace SecondStay\Notification;

use SecondStay\Auth\User;
use SecondStay\I18n\Locales;
use SecondStay\I18n\Translator;
use SecondStay\Logging\Logger;
use SecondStay\Mail\MailAddress;
use SecondStay\Mail\MailService;
use SecondStay\Push\PushMessage;
use SecondStay\Push\PushProvider;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Settings\SettingsService;
use Throwable;

/**
 * Point d'entrée unique des notifications (ARCHITECTURE.md §14).
 *
 * E-mail et push sont **indépendants** : si le push est actif, les deux sont
 * envoyés, et l'échec de l'un n'empêche jamais l'autre. Chaque tentative est
 * journalisée séparément, dans la langue du destinataire.
 */
final class NotificationService
{
    public function __construct(
        private readonly MailService $mail,
        private readonly PushProvider $push,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly NotificationRepository $notifications,
        private readonly NotificationPreferenceRepository $preferences,
        private readonly Translator $translator,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
    ) {
    }

    public function isPushEnabled(): bool
    {
        return $this->settings->bool('notification.push_enabled') && $this->push->isConfigured();
    }

    public function pushPublicKey(): string
    {
        return $this->isPushEnabled() ? $this->push->publicKey() : '';
    }

    /**
     * Notifie un utilisateur sur tous les canaux actifs.
     *
     * @param array<string, mixed> $context variables du gabarit e-mail
     *
     * @return array{email: bool, push: int, attempted: array<string, bool>}
     */
    public function notify(NotificationEvent $event, User $user, array $context = [], string $reference = ''): array
    {
        $locale = Locales::isSupported($user->locale) ? $user->locale : Locales::FALLBACK;

        $emailSent = $this->sendEmail($event, $user, $locale, $context, $reference);
        $pushSent = $this->sendPush($event, $user, $locale, $context, $reference);

        return [
            'email' => $emailSent,
            'push' => $pushSent,
            'attempted' => [
                'email' => $this->preferences->isEnabled($user->id, NotificationChannel::Email),
                'push' => $this->isPushEnabled() && $this->preferences->isEnabled($user->id, NotificationChannel::Push),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function sendEmail(
        NotificationEvent $event,
        User $user,
        string $locale,
        array $context,
        string $reference,
    ): bool {
        if (!$this->preferences->isEnabled($user->id, NotificationChannel::Email)) {
            $this->notifications->record(
                $event,
                NotificationChannel::Email,
                'skipped',
                $user->id,
                $locale,
                '',
                $reference,
                'notification.skipped.disabled',
                $this->logger->correlationId(),
            );

            return false;
        }

        try {
            $result = $this->mail->send(
                $event->mailTemplate(),
                new MailAddress($user->email, $user->displayName()),
                $locale,
                $context + [
                    'first_name' => $user->firstName,
                    'event' => $event->value,
                    'heading' => $this->translator->trans(
                        $event->titleKey(),
                        $this->parameters($context, $user),
                        $locale,
                    ),
                    'message' => $this->translator->trans(
                        $event->mailBodyKey(),
                        $this->parameters($context, $user),
                        $locale,
                    ),
                    'action_label' => $this->translator->trans($event->actionKey(), [], $locale),
                ],
                $user->id,
                $event->subjectKey(),
                is_int($context['booking_id'] ?? null) ? $context['booking_id'] : null,
                is_string($context['reference'] ?? null) ? $context['reference'] : '',
            );
        } catch (Throwable $throwable) {
            $this->notifications->record(
                $event,
                NotificationChannel::Email,
                NotificationRepository::FAILED,
                $user->id,
                $locale,
                '',
                $reference,
                $throwable->getMessage(),
                $this->logger->correlationId(),
            );

            return false;
        }

        $this->notifications->record(
            $event,
            NotificationChannel::Email,
            $result['ok'] ? 'sent' : NotificationRepository::FAILED,
            $user->id,
            $locale,
            $this->translator->trans($event->subjectKey(), $this->parameters($context, $user), $locale),
            $reference,
            $result['error'],
            $this->logger->correlationId(),
        );

        return $result['ok'];
    }

    /**
     * Paramètres scalaires utilisables dans les traductions.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, string|int|float>
     */
    private function parameters(array $context, User $user): array
    {
        $parameters = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters + [
            'first_name' => $user->firstName,
            'property' => $this->settings->string('property.name'),
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return int nombre d'appareils réellement notifiés
     */
    private function sendPush(
        NotificationEvent $event,
        User $user,
        string $locale,
        array $context,
        string $reference,
    ): int {
        if (!$this->isPushEnabled() || !$this->preferences->isEnabled($user->id, NotificationChannel::Push)) {
            return 0;
        }

        $subscriptions = $this->subscriptions->forUser($user->id);
        if ($subscriptions === []) {
            return 0;
        }

        $parameters = $this->parameters($context, $user);

        $message = new PushMessage(
            $this->translator->trans($event->titleKey(), $parameters, $locale),
            $this->translator->trans($event->bodyKey(), $parameters, $locale),
            is_string($context['push_path'] ?? null) ? $context['push_path'] : '/' . $locale . '/account',
            $event->value,
            $locale,
        );

        $delivered = 0;
        foreach ($subscriptions as $subscription) {
            $result = $this->push->send($subscription, $message);

            if ($result['ok']) {
                $this->subscriptions->markUsed($subscription->id);
                $delivered++;
            } elseif ($result['expired']) {
                // Abonnement révoqué par le navigateur : il ne reviendra pas.
                $this->subscriptions->delete($subscription->id);
            } else {
                $this->subscriptions->markFailed($subscription->id);
            }

            $this->notifications->record(
                $event,
                NotificationChannel::Push,
                $result['ok'] ? 'sent' : NotificationRepository::FAILED,
                $user->id,
                $locale,
                $message->title,
                $reference,
                $result['error'],
                $this->logger->correlationId(),
            );
        }

        $this->logger->info('notification', 'Notification poussée', [
            'event' => $event->value,
            'devices' => count($subscriptions),
            'delivered' => $delivered,
        ]);

        return $delivered;
    }
}
