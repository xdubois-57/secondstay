<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;

final class AuthController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function showLogin(RequestContext $context, array $params = []): Response
    {
        if ($this->auth()->isAuthenticated()) {
            return $this->redirectToRoute($context, 'admin.dashboard');
        }

        return $this->renderLogin($context, null, '');
    }

    /**
     * @param array<string, string> $params
     */
    public function login(RequestContext $context, array $params = []): Response
    {
        $email = trim((string) $context->request->input('email', ''));
        $password = (string) $context->request->input('password', '');

        $result = $this->auth()->attempt(
            $email,
            $password,
            $context->request->ip(),
            $context->request->userAgent(),
        );

        if ($result['ok'] === false) {
            return $this->renderLogin($context, $result['error'], $email, 401);
        }

        $this->flashSuccess('auth.login.welcome');

        $target = $result['user']->isOperational() ? 'admin.dashboard' : 'home';

        return $this->redirectToRoute($context, $target, [], $result['user']->locale);
    }

    /**
     * @param array<string, string> $params
     */
    public function logout(RequestContext $context, array $params = []): Response
    {
        $this->auth()->logout();
        $this->session()->flash('success', 'auth.logout.done');

        return $this->redirectToRoute($context, 'home');
    }

    private function renderLogin(RequestContext $context, ?string $errorKey, string $email, int $status = 200): Response
    {
        return $this->render('auth/login.html.twig', [
            'meta_title' => $this->trans('auth.login.title'),
            'error_key' => $errorKey,
            'email' => $email,
        ], $status);
    }
}
