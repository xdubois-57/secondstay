<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Controller\Admin\AdminBackupController;
use SecondStay\Controller\Admin\AdminDashboardController;
use SecondStay\Controller\Admin\AdminDiagnosticsController;
use SecondStay\Controller\Admin\AdminLogController;
use SecondStay\Controller\Admin\AdminMaintenanceController;
use SecondStay\Controller\Admin\AdminSettingsController;
use SecondStay\Controller\Admin\AdminUpdateController;
use SecondStay\Controller\Admin\AdminUserController;
use SecondStay\Controller\ApiController;
use SecondStay\Controller\AuthController;
use SecondStay\Controller\HomeController;
use SecondStay\Controller\InstallController;

/**
 * Table de routage de l'application.
 *
 * Les routes « localised » acceptent un préfixe de langue (/fr, /en, /nl, /de).
 * Les segments de chemin restent stables et neutres : la langue est portée par
 * le préfixe, jamais par le slug, ce qui garantit des URLs durables et des
 * `hreflang` cohérents entre les quatre langues.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // --- Site public -------------------------------------------------
        $router->get('/', [HomeController::class, 'index'], 'home');

        // --- Installation --------------------------------------------------
        $router->get('/install', [InstallController::class, 'show'], 'install');
        $router->post('/install', [InstallController::class, 'submit'], 'install.submit');
        $router->post('/install/test-database', [InstallController::class, 'testDatabase'], 'install.test_database');

        // --- Authentification ----------------------------------------------
        $router->get('/login', [AuthController::class, 'showLogin'], 'login');
        $router->post('/login', [AuthController::class, 'login'], 'login.submit');
        $router->post('/logout', [AuthController::class, 'logout'], 'logout');

        // --- Administration --------------------------------------------------
        $router->get('/admin', [AdminDashboardController::class, 'index'], 'admin.dashboard');

        $router->get('/admin/settings', [AdminSettingsController::class, 'index'], 'admin.settings');
        $router->post('/admin/settings', [AdminSettingsController::class, 'save'], 'admin.settings.save');

        $router->get('/admin/users', [AdminUserController::class, 'index'], 'admin.users');
        $router->post('/admin/users', [AdminUserController::class, 'create'], 'admin.users.create');
        $router->post('/admin/users/{id:\d+}/role', [AdminUserController::class, 'changeRole'], 'admin.users.role');
        $router->post('/admin/users/{id:\d+}/delete', [AdminUserController::class, 'delete'], 'admin.users.delete');

        $router->get('/admin/logs', [AdminLogController::class, 'index'], 'admin.logs');
        $router->post('/admin/logs/purge', [AdminLogController::class, 'purge'], 'admin.logs.purge');
        $router->get('/admin/audit', [AdminLogController::class, 'auditTrail'], 'admin.audit');

        $router->get('/admin/diagnostics', [AdminDiagnosticsController::class, 'index'], 'admin.diagnostics');

        $router->get('/admin/backups', [AdminBackupController::class, 'index'], 'admin.backups');
        $router->post('/admin/backups/create', [AdminBackupController::class, 'create'], 'admin.backups.create');
        $router->post('/admin/backups/{id}/restore', [AdminBackupController::class, 'restore'], 'admin.backups.restore');
        $router->post('/admin/backups/{id}/delete', [AdminBackupController::class, 'delete'], 'admin.backups.delete');
        $router->get('/admin/backups/{id}/download', [AdminBackupController::class, 'download'], 'admin.backups.download');
        $router->get('/admin/backups/{id}/verify', [AdminBackupController::class, 'verify'], 'admin.backups.verify');

        $router->get('/admin/updates', [AdminUpdateController::class, 'index'], 'admin.updates');
        $router->post('/admin/updates/check', [AdminUpdateController::class, 'check'], 'admin.updates.check');
        $router->post('/admin/updates/install', [AdminUpdateController::class, 'install'], 'admin.updates.install');

        $router->post('/admin/maintenance', [AdminMaintenanceController::class, 'toggle'], 'admin.maintenance.toggle');

        // --- API technique ------------------------------------------------
        $router->get('/api/version', [ApiController::class, 'version'], 'api.version', false);
        $router->get('/api/health', [ApiController::class, 'health'], 'api.health', false);
    }
}
