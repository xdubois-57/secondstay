<?php

declare(strict_types=1);

namespace SecondStay\Controller\Account;

use InvalidArgumentException;
use SecondStay\Auth\Role;
use SecondStay\Controller\AbstractController;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Notification\NotificationService;
use SecondStay\Push\PushSubscription;
use SecondStay\Push\PushSubscriptionRepository;

/**
 * Abonnement du navigateur aux notifications push.
 *
 * Un abonnement appartient toujours à un compte authentifié : le push n'est
 * jamais envoyé à un visiteur anonyme.
 */
final class PushController extends AbstractController
{
    public const MAX_DEVICES = 10;

    /**
     * Clé publique VAPID à transmettre à `pushManager.subscribe()`.
     *
     * @param array<string, string> $params
     */
    public function publicKey(RequestContext $context, array $params = []): Response
    {
        $this->assertEnabled();

        return Response::json(['key' => $this->notifications()->pushPublicKey()]);
    }

    /**
     * @param array<string, string> $params
     */
    public function subscribe(RequestContext $context, array $params = []): Response
    {
        $this->assertEnabled();
        $user = $this->requireRole(Role::Customer);

        /** @var array{endpoint?: string, keys?: array{p256dh?: string, auth?: string}} $payload */
        $payload = $context->request->json();
        $keys = is_array($payload['keys'] ?? null) ? $payload['keys'] : [];

        $repository = $this->container->get(PushSubscriptionRepository::class);

        try {
            $subscription = new PushSubscription(
                (string) ($payload['endpoint'] ?? ''),
                (string) ($keys['p256dh'] ?? ''),
                (string) ($keys['auth'] ?? ''),
                $user->id,
                $user->locale,
            );
        } catch (InvalidArgumentException $exception) {
            return Response::json(['ok' => false, 'error' => $this->translateError($exception->getMessage())], 422);
        }

        // Un abonnement déjà connu est rafraîchi, pas compté deux fois.
        $known = $repository->findByEndpointHash($subscription->endpointHash()) !== null;
        if (!$known && $repository->countForUser($user->id) >= self::MAX_DEVICES) {
            return Response::json(['ok' => false, 'error' => $this->trans('push.error.too_many_devices')], 429);
        }

        $repository->save($subscription, $context->request->userAgent());
        $this->audit()->record('push.subscribed', 'user', (string) $user->id, null, [
            'service' => $subscription->serviceHost(),
        ], $user->id, $user->email);

        return Response::json(['ok' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function unsubscribe(RequestContext $context, array $params = []): Response
    {
        $this->assertEnabled();
        $user = $this->requireRole(Role::Customer);

        /** @var array{endpoint?: string} $payload */
        $payload = $context->request->json();
        $endpoint = (string) ($payload['endpoint'] ?? '');

        if (!PushSubscription::isValidEndpoint($endpoint)) {
            return Response::json(['ok' => false, 'error' => $this->trans('push.error.invalid_endpoint')], 422);
        }

        $repository = $this->container->get(PushSubscriptionRepository::class);
        $row = $repository->findByEndpointHash(hash('sha256', $endpoint));

        // On ne supprime que ses propres abonnements.
        if ($row === null || (int) $row['user_id'] !== $user->id) {
            return Response::json(['ok' => true]);
        }

        $repository->delete((int) $row['id']);
        $this->audit()->record('push.unsubscribed', 'user', (string) $user->id, null, null, $user->id, $user->email);

        return Response::json(['ok' => true]);
    }

    private function notifications(): NotificationService
    {
        return $this->container->get(NotificationService::class);
    }

    private function assertEnabled(): void
    {
        if (!$this->notifications()->isPushEnabled()) {
            throw new NotFoundException('Les notifications push sont désactivées.');
        }
    }

    private function translateError(string $key): string
    {
        return str_starts_with($key, 'push.error.') ? $this->trans($key) : $this->trans('push.error.generic');
    }
}
