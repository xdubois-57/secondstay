<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\Role;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;

/**
 * Gestion des comptes : plusieurs administrateurs et plusieurs responsables
 * locaux sont supportés (AGENTS.md §7).
 */
final class AdminUserController extends AdminController
{
    protected function section(): string
    {
        return 'users';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        return $this->renderList([], []);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(RequestContext $context, array $params = []): Response
    {
        $actor = $this->requireAdministrator();
        $users = $this->container->get(UserRepository::class);
        $hasher = $this->container->get(PasswordHasher::class);

        $email = mb_strtolower(trim((string) $context->request->input('email', '')));
        $password = (string) $context->request->input('password', '');
        $firstName = trim((string) $context->request->input('first_name', ''));
        $lastName = trim((string) $context->request->input('last_name', ''));
        $phone = trim((string) $context->request->input('phone', ''));
        $role = Role::fromString((string) $context->request->input('role', Role::LocalManager->value));
        $locale = Locales::normalise((string) $context->request->input('locale', $context->locale)) ?? $context->locale;

        $errors = [];
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'admin.users.error.email';
        } elseif ($users->emailExists($email)) {
            $errors['email'] = 'admin.users.error.email_taken';
        }

        $evaluation = $hasher->evaluate($password);
        if ($evaluation['errors'] !== []) {
            $errors['password'] = $evaluation['errors'][0];
        }
        if ($firstName === '' || $lastName === '') {
            $errors['name'] = 'admin.users.error.name';
        }

        if ($errors !== []) {
            return $this->renderList($errors, [
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'role' => $role->value,
                'locale' => $locale,
            ], 422);
        }

        $id = $users->create(
            $email,
            $hasher->hash($password),
            $firstName,
            $lastName,
            $phone,
            $role,
            $locale,
            UserStatus::Active,
        );

        $this->audit()->record(
            'user.created',
            'user',
            (string) $id,
            null,
            ['email' => $email, 'role' => $role->value],
            $actor->id,
            $actor->email,
        );

        $this->flashSuccess('admin.users.created');

        return $this->redirectToRoute($context, 'admin.users');
    }

    /**
     * @param array<string, string> $params
     */
    public function changeRole(RequestContext $context, array $params = []): Response
    {
        $actor = $this->requireAdministrator();
        $users = $this->container->get(UserRepository::class);

        $id = (int) ($params['id'] ?? 0);
        $target = $users->findById($id);
        if ($target === null) {
            $this->flashError('admin.users.error.not_found');

            return $this->redirectToRoute($context, 'admin.users');
        }

        $role = Role::fromString((string) $context->request->input('role', $target->role->value));

        // Il doit toujours rester au moins un administrateur actif.
        if (
            $target->role === Role::Administrator
            && $role !== Role::Administrator
            && $users->countAdministrators() <= 1
        ) {
            $this->flashError('admin.users.error.last_administrator');

            return $this->redirectToRoute($context, 'admin.users');
        }

        $users->update($id, ['role' => $role->value]);
        $this->audit()->record(
            'user.role_changed',
            'user',
            (string) $id,
            ['role' => $target->role->value],
            ['role' => $role->value],
            $actor->id,
            $actor->email,
        );

        $this->flashSuccess('admin.users.role_changed');

        return $this->redirectToRoute($context, 'admin.users');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(RequestContext $context, array $params = []): Response
    {
        $actor = $this->requireAdministrator();
        $users = $this->container->get(UserRepository::class);

        $id = (int) ($params['id'] ?? 0);
        $target = $users->findById($id);
        if ($target === null) {
            $this->flashError('admin.users.error.not_found');

            return $this->redirectToRoute($context, 'admin.users');
        }

        if ($target->id === $actor->id) {
            $this->flashError('admin.users.error.self_delete');

            return $this->redirectToRoute($context, 'admin.users');
        }

        if ($target->role === Role::Administrator && $users->countAdministrators() <= 1) {
            $this->flashError('admin.users.error.last_administrator');

            return $this->redirectToRoute($context, 'admin.users');
        }

        $users->delete($id);
        $this->audit()->record(
            'user.deleted',
            'user',
            (string) $id,
            ['email' => $target->email, 'role' => $target->role->value],
            null,
            $actor->id,
            $actor->email,
        );

        $this->flashSuccess('admin.users.deleted');

        return $this->redirectToRoute($context, 'admin.users');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $values
     */
    private function renderList(array $errors, array $values, int $status = 200): Response
    {
        $users = $this->container->get(UserRepository::class);

        return $this->renderAdmin('admin/users.html.twig', [
            'meta_title' => $this->trans('admin.users.title'),
            'users' => array_map(
                static fn (\SecondStay\Auth\User $user): array => $user->toSafeArray() + [
                    'last_login_at' => $user->lastLoginAt,
                ],
                $users->all()
            ),
            'roles' => array_map(static fn (Role $role): string => $role->value, Role::cases()),
            'locales' => Locales::ALL,
            'errors' => $errors,
            'values' => $values,
            'administrator_count' => $users->countAdministrators(),
            'min_password_length' => PasswordHasher::MIN_LENGTH,
        ], $status);
    }
}
