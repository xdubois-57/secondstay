<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Auth\Role;
use SecondStay\Auth\User;
use SecondStay\Controller\AbstractController;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;

/**
 * Base des contrôleurs d'administration : l'autorisation est vérifiée
 * systématiquement côté serveur, jamais par simple masquage d'un bouton.
 */
abstract class AdminController extends AbstractController
{
    protected function requireOperational(): User
    {
        return $this->requireRole(Role::LocalManager);
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function renderAdmin(string $template, array $context = [], int $status = 200): Response
    {
        return $this->render($template, $context + ['admin_section' => $this->section()], $status);
    }

    abstract protected function section(): string;

    protected function backToSection(RequestContext $context, string $route): Response
    {
        return $this->redirectToRoute($context, $route);
    }
}
