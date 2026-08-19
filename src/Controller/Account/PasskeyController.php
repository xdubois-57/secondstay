<?php

declare(strict_types=1);

namespace SecondStay\Controller\Account;

use SecondStay\Auth\Role;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\WebAuthn\WebAuthnService;
use SecondStay\Controller\AbstractController;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Security\RateLimiter;
use Throwable;

/**
 * Endpoints JSON WebAuthn.
 *
 * Toutes les réponses d'erreur sont des clés de traduction : aucun détail
 * technique n'est exposé au navigateur.
 */
final class PasskeyController extends AbstractController
{
    public const MAX_ATTEMPTS = 20;

    /**
     * @param array<string, string> $params
     */
    public function registrationOptions(RequestContext $context, array $params = []): Response
    {
        $this->assertEnabled();
        $user = $this->requireRole(Role::Customer);

        return Response::json($this->container->get(WebAuthnService::class)->registrationOptions($user));
    }

    /**
     * @param array<string, string> $params
     */
    public function register(RequestContext $context, array $params = []): Response
    {
        $this->assertEnabled();
        $user = $this->requireRole(Role::Customer);

        /** @var array{response?: array<string, mixed>, label?: string} $payload */
        $payload = $context->request->json();
        /** @var array<string, mixed> $response */
        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];

        try {
            $this->container->get(WebAuthnService::class)->completeRegistration(
                $user,
                $response,
                mb_substr((string) ($payload['label'] ?? ''), 0, 120),
            );
        } catch (Throwable $throwable) {
            return Response::json(['ok' => false, 'error' => $this->translateError($throwable->getMessage())], 422);
        }

        return Response::json(['ok' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function authenticationOptions(RequestContext $context, array $params = []): Response
    {
        $this->assertEnabled();

        return Response::json($this->container->get(WebAuthnService::class)->authenticationOptions());
    }

    /**
     * @param array<string, string> $params
     */
    public function authenticate(RequestContext $context, array $params = []): Response
    {
        $this->assertEnabled();

        $limit = $this->container->get(RateLimiter::class)->hit('passkey:ip:' . $context->request->ip(), self::MAX_ATTEMPTS);
        if (!$limit['allowed']) {
            return Response::json(['ok' => false, 'error' => $this->trans('auth.login.rate_limited')], 429);
        }

        /** @var array{response?: array<string, mixed>} $payload */
        $payload = $context->request->json();
        /** @var array<string, mixed> $response */
        $response = is_array($payload['response'] ?? null) ? $payload['response'] : [];

        try {
            $result = $this->container->get(WebAuthnService::class)->verifyAssertion($response);
        } catch (Throwable $throwable) {
            $this->logger()->info('auth', 'Échec d’authentification par passkey');

            return Response::json(['ok' => false, 'error' => $this->translateError($throwable->getMessage())], 401);
        }

        $user = $this->container->get(UserRepository::class)->findById($result['user_id']);
        if ($user === null || !$user->status->canAuthenticate()) {
            return Response::json(['ok' => false, 'error' => $this->trans('auth.login.invalid_credentials')], 401);
        }

        $this->auth()->startSession($user, $context->request->ip(), $context->request->userAgent());
        $this->audit()->record('auth.login_passkey', 'user', (string) $user->id, null, null, $user->id, $user->email);

        return Response::json([
            'ok' => true,
            'redirect' => $context->request->basePath . $this->router()->path(
                $user->isOperational() ? 'admin.dashboard' : 'account.profile',
                [],
                $user->locale
            ),
        ]);
    }

    private function assertEnabled(): void
    {
        if (!$this->settings()->bool('account.allow_passkeys')) {
            throw new NotFoundException('Les passkeys sont désactivées.');
        }

        // Sans domaine enregistrable, aucun navigateur n'acceptera la clé :
        // l'endpoint n'existe pas plutôt que d'échouer à chaque appel.
        if (!$this->container->get(WebAuthnService::class)->isAvailable()) {
            throw new NotFoundException('Les passkeys ne sont pas disponibles sur ce domaine.');
        }
    }

    private function translateError(string $key): string
    {
        return str_starts_with($key, 'webauthn.') ? $this->trans($key) : $this->trans('webauthn.error.generic');
    }
}
