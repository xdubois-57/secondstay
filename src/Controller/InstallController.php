<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Auth\AuthService;
use SecondStay\Auth\UserRepository;
use SecondStay\Core\Exception\ValidationException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Database\DatabaseConfig;
use SecondStay\I18n\Locales;
use SecondStay\Installer\Installer;
use SecondStay\Installer\InstallToken;
use SecondStay\Installer\RequirementChecker;
use Throwable;

/**
 * Assistant d'installation.
 *
 * Il n'est accessible que tant que l'installation n'est pas terminée : le
 * Kernel renvoie 404 dès qu'un administrateur actif existe.
 */
final class InstallController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        return $this->renderForm($context, [], []);
    }

    /**
     * Test de connexion à la base sans écrire quoi que ce soit.
     *
     * @param array<string, string> $params
     */
    public function testDatabase(RequestContext $context, array $params = []): Response
    {
        $installer = $this->container->get(Installer::class);
        $result = $installer->testConnection($this->databaseConfigFromRequest($context));

        if ($result['ok'] === true) {
            return Response::json([
                'ok' => true,
                'message' => $this->trans('install.database.connection_ok'),
                'server' => $result['server'],
            ]);
        }

        return Response::json([
            'ok' => false,
            'message' => $this->trans($result['error']),
        ], 200);
    }

    /**
     * @param array<string, string> $params
     */
    public function submit(RequestContext $context, array $params = []): Response
    {
        $request = $context->request;
        $installer = $this->container->get(Installer::class);

        $input = [
            'database' => $this->databaseConfigFromRequest($context),
            'admin_email' => (string) $request->input('admin_email', ''),
            'admin_password' => (string) $request->input('admin_password', ''),
            'admin_first_name' => (string) $request->input('admin_first_name', ''),
            'admin_last_name' => (string) $request->input('admin_last_name', ''),
            'admin_phone' => (string) $request->input('admin_phone', ''),
            'locale' => (string) $request->input('locale', $context->locale),
            'property_name' => (string) $request->input('property_name', ''),
            'timezone' => (string) $request->input('timezone', 'Europe/Paris'),
            'site_url' => $request->baseUrl(),
        ];

        if ((string) $request->input('admin_password', '') !== (string) $request->input('admin_password_confirm', '')) {
            return $this->renderForm(
                $context,
                ['admin_password_confirm' => 'install.error.password_mismatch'],
                $input,
                422,
            );
        }

        try {
            $result = $installer->install($input);
        } catch (ValidationException $exception) {
            return $this->renderForm($context, $exception->errors(), $input, 422);
        } catch (Throwable $throwable) {
            $this->logger()->error('install', 'Installation échouée', ['reason' => $throwable->getMessage()]);

            return $this->renderForm($context, ['general' => 'install.error.generic'], $input, 500);
        }

        // Le conteneur a été construit avant l'écriture de config/local.php :
        // on connecte immédiatement le premier administrateur sur la base
        // fraîchement installée.
        $users = new UserRepository($result['database']);
        $admin = $users->findById($result['admin_id']);
        if ($admin !== null) {
            $session = $this->session();
            $session->regenerate();
            $session->set(AuthService::SESSION_USER_KEY, $admin->id);
            (new \SecondStay\Auth\SessionRepository($result['database']))->create(
                \SecondStay\Security\Tokens::hash($session->id()),
                $admin->id,
                $this->config()->int('security.session_lifetime_minutes', 120),
                $context->request->ip(),
                $context->request->userAgent(),
            );
        }

        // Le jeton n'a plus d'objet : la fenêtre qu'il protégeait — une
        // instance sans administrateur — vient de se refermer. Le laisser en
        // place serait un secret de plus sur le disque, pour rien.
        $this->container->get(InstallToken::class)->delete();

        $locale = Locales::normalise($input['locale']) ?? $context->locale;
        $this->flashSuccess('install.success');

        return $this->redirectToRoute($context, 'admin.dashboard', [], $locale);
    }

    /**
     * Rassemble les identifiants de base saisis dans l'assistant.
     *
     * Rien n'est écrit à partir d'eux avant qu'une connexion n'ait réussi :
     * un `config/local.php` écrit sur des identifiants faux laisserait une
     * installation qui ne démarre plus et que l'assistant ne reprend pas.
     */
    private function databaseConfigFromRequest(RequestContext $context): DatabaseConfig
    {
        $request = $context->request;

        return new DatabaseConfig(
            trim((string) $request->input('db_host', '127.0.0.1')),
            (int) $request->input('db_port', '3306'),
            trim((string) $request->input('db_name', '')),
            trim((string) $request->input('db_user', '')),
            (string) $request->input('db_password', ''),
            trim((string) $request->input('db_charset', 'utf8mb4')),
        );
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $input
     */
    private function renderForm(RequestContext $context, array $errors, array $input, int $status = 200): Response
    {
        $checker = $this->container->get(RequirementChecker::class);

        $values = [
            'db_host' => (string) ($context->request->input('db_host', '127.0.0.1') ?? '127.0.0.1'),
            'db_port' => (string) ($context->request->input('db_port', '3306') ?? '3306'),
            'db_name' => (string) ($context->request->input('db_name', '') ?? ''),
            'db_user' => (string) ($context->request->input('db_user', '') ?? ''),
            'db_charset' => (string) ($context->request->input('db_charset', 'utf8mb4') ?? 'utf8mb4'),
            'admin_email' => is_string($input['admin_email'] ?? null) ? $input['admin_email'] : '',
            'admin_first_name' => is_string($input['admin_first_name'] ?? null) ? $input['admin_first_name'] : '',
            'admin_last_name' => is_string($input['admin_last_name'] ?? null) ? $input['admin_last_name'] : '',
            'admin_phone' => is_string($input['admin_phone'] ?? null) ? $input['admin_phone'] : '',
            'property_name' => is_string($input['property_name'] ?? null) ? $input['property_name'] : '',
            'timezone' => is_string($input['timezone'] ?? null) && $input['timezone'] !== ''
                ? $input['timezone']
                : 'Europe/Paris',
            'locale' => $context->locale,
        ];

        return $this->render('install/install.html.twig', [
            'meta_title' => $this->trans('install.title'),
            'requirements' => $checker->check(),
            'requirements_ok' => $checker->isSatisfied(),
            'errors' => $errors,
            'values' => $values,
            'timezones' => $this->timezoneChoices(),
            'min_password_length' => \SecondStay\Auth\PasswordHasher::MIN_LENGTH,
        ], $status);
    }

    /**
     * @return list<string>
     */
    private function timezoneChoices(): array
    {
        return [
            'Europe/Paris',
            'Europe/Brussels',
            'Europe/Amsterdam',
            'Europe/Berlin',
            'Europe/London',
            'Indian/Reunion',
            'America/Guadeloupe',
            'America/Martinique',
            'UTC',
        ];
    }
}
