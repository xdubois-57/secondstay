<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Auth\AccountService;
use SecondStay\Auth\UserRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;
use SecondStay\Notification\NotificationEvent;
use SecondStay\Notification\NotificationService;

/**
 * Parcours public de compte : inscription, confirmation, réinitialisation.
 */
final class AccountController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function showSignup(RequestContext $context, array $params = []): Response
    {
        $this->assertSignupEnabled();

        if ($this->auth()->isAuthenticated()) {
            return $this->redirectToRoute($context, 'account.profile');
        }

        return $this->renderSignup($context, [], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function signup(RequestContext $context, array $params = []): Response
    {
        $this->assertSignupEnabled();

        $input = [
            'email' => (string) $context->request->input('email', ''),
            'password' => (string) $context->request->input('password', ''),
            'first_name' => (string) $context->request->input('first_name', ''),
            'last_name' => (string) $context->request->input('last_name', ''),
            'phone' => (string) $context->request->input('phone', ''),
            'locale' => $context->locale,
            'accept_terms' => $context->request->input('accept_terms'),
        ];

        try {
            $this->container->get(AccountService::class)->register(
                $input,
                $context->request->ip(),
                $context->locale,
            );
        } catch (ValidationException $exception) {
            return $this->renderSignup($context, $exception->errors(), $input, 422);
        }

        return $this->render('auth/signup-sent.html.twig', [
            'meta_title' => $this->trans('account.signup.sent_title'),
            'email' => $input['email'],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function confirm(RequestContext $context, array $params = []): Response
    {
        $token = (string) ($context->request->query('token') ?? '');
        $result = $this->container->get(AccountService::class)->confirmEmail($token);

        if ($result['ok'] === false || $result['user'] === null) {
            return $this->render('auth/confirm.html.twig', [
                'meta_title' => $this->trans('account.confirm.title'),
                'confirmed' => false,
                'error_key' => $result['error'],
            ], 400);
        }

        // La confirmation vaut preuve de possession de la boîte mail :
        // l'utilisateur est connecté immédiatement.
        $this->auth()->startSession($result['user'], $context->request->ip(), $context->request->userAgent());

        // Premier événement notifiable du cycle de vie : e-mail et push sont
        // envoyés indépendamment, dans la langue du compte.
        $this->container->get(NotificationService::class)->notify(
            NotificationEvent::AccountConfirmed,
            $result['user'],
            ['action_path' => '/' . $result['user']->locale . '/account'],
            'user:' . $result['user']->id,
        );
        $this->flashSuccess('account.confirm.success');

        return $this->redirectToRoute($context, 'account.profile', [], $result['user']->locale);
    }

    /**
     * @param array<string, string> $params
     */
    public function showForgotPassword(RequestContext $context, array $params = []): Response
    {
        return $this->render('auth/forgot-password.html.twig', [
            'meta_title' => $this->trans('account.forgot.title'),
            'sent' => false,
            'email' => '',
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function forgotPassword(RequestContext $context, array $params = []): Response
    {
        $email = trim((string) $context->request->input('email', ''));

        $this->container->get(AccountService::class)->requestPasswordReset(
            $email,
            $context->request->ip(),
            $context->locale,
        );

        // La réponse est identique qu'un compte existe ou non.
        return $this->render('auth/forgot-password.html.twig', [
            'meta_title' => $this->trans('account.forgot.title'),
            'sent' => true,
            'email' => $email,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function showResetPassword(RequestContext $context, array $params = []): Response
    {
        return $this->render('auth/reset-password.html.twig', [
            'meta_title' => $this->trans('account.reset.title'),
            'token' => (string) ($context->request->query('token') ?? ''),
            'error_key' => null,
            'min_password_length' => \SecondStay\Auth\PasswordHasher::MIN_LENGTH,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function resetPassword(RequestContext $context, array $params = []): Response
    {
        $token = (string) $context->request->input('token', '');
        $password = (string) $context->request->input('password', '');
        $confirmation = (string) $context->request->input('password_confirm', '');

        if ($password !== $confirmation) {
            return $this->renderResetError($context, $token, 'account.error.password_mismatch');
        }

        $result = $this->container->get(AccountService::class)->resetPassword($token, $password);
        if ($result['ok'] === false) {
            return $this->renderResetError($context, $token, $result['error']);
        }

        $this->flashSuccess('account.reset.success');

        return $this->redirectToRoute($context, 'login');
    }

    private function renderResetError(RequestContext $context, string $token, string $errorKey): Response
    {
        return $this->render('auth/reset-password.html.twig', [
            'meta_title' => $this->trans('account.reset.title'),
            'token' => $token,
            'error_key' => $errorKey,
            'min_password_length' => \SecondStay\Auth\PasswordHasher::MIN_LENGTH,
        ], 422);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $values
     */
    private function renderSignup(RequestContext $context, array $errors, array $values, int $status = 200): Response
    {
        return $this->render('auth/signup.html.twig', [
            'meta_title' => $this->trans('account.signup.title'),
            'errors' => $errors,
            'values' => [
                'email' => is_string($values['email'] ?? null) ? $values['email'] : '',
                'first_name' => is_string($values['first_name'] ?? null) ? $values['first_name'] : '',
                'last_name' => is_string($values['last_name'] ?? null) ? $values['last_name'] : '',
                'phone' => is_string($values['phone'] ?? null) ? $values['phone'] : '',
            ],
            'locales' => Locales::ALL,
            'min_password_length' => \SecondStay\Auth\PasswordHasher::MIN_LENGTH,
        ], $status);
    }

    private function assertSignupEnabled(): void
    {
        if (!$this->settings()->bool('account.allow_signup')) {
            throw new NotFoundException('Les inscriptions sont désactivées.');
        }
    }
}
