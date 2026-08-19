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
use SecondStay\Controller\Account\PasskeyController;
use SecondStay\Controller\Account\ProfileController;
use SecondStay\Controller\AccountController;
use SecondStay\Controller\ApiController;
use SecondStay\Controller\AuthController;
use SecondStay\Controller\DevMailboxController;
use SecondStay\Controller\Admin\AdminContentController;
use SecondStay\Controller\Admin\AdminMediaController;
use SecondStay\Controller\InstallController;
use SecondStay\Controller\MediaController;
use SecondStay\Controller\PageController;
use SecondStay\Controller\SeoController;

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
        $router->get('/', [PageController::class, 'home'], 'home');

        // --- SEO ------------------------------------------------------------
        $router->get('/sitemap.xml', [SeoController::class, 'sitemap'], 'seo.sitemap', false);
        $router->get('/robots.txt', [SeoController::class, 'robots'], 'seo.robots', false);

        // --- Médias -----------------------------------------------------------
        $router->get(
            '/media/{variant:thumb|large|original}/{filename:[a-z0-9]+\.[a-z0-9]{2,5}}',
            [MediaController::class, 'show'],
            'media.show',
            false
        );

        // --- Installation --------------------------------------------------
        $router->get('/install', [InstallController::class, 'show'], 'install');
        $router->post('/install', [InstallController::class, 'submit'], 'install.submit');
        $router->post('/install/test-database', [InstallController::class, 'testDatabase'], 'install.test_database');

        // --- Authentification ----------------------------------------------
        $router->get('/login', [AuthController::class, 'showLogin'], 'login');
        $router->post('/login', [AuthController::class, 'login'], 'login.submit');
        $router->post('/logout', [AuthController::class, 'logout'], 'logout');

        // --- Comptes clients ---------------------------------------------
        $router->get('/account/signup', [AccountController::class, 'showSignup'], 'account.signup');
        $router->post('/account/signup', [AccountController::class, 'signup'], 'account.signup.submit');
        $router->get('/account/confirm', [AccountController::class, 'confirm'], 'account.confirm');
        $router->get('/account/forgot-password', [AccountController::class, 'showForgotPassword'], 'account.forgot');
        $router->post('/account/forgot-password', [AccountController::class, 'forgotPassword'], 'account.forgot.submit');
        $router->get('/account/reset', [AccountController::class, 'showResetPassword'], 'account.reset');
        $router->post('/account/reset', [AccountController::class, 'resetPassword'], 'account.reset.submit');

        $router->get('/account', [ProfileController::class, 'show'], 'account.profile');
        $router->post('/account/profile', [ProfileController::class, 'updateProfile'], 'account.profile.save');
        $router->post('/account/password', [ProfileController::class, 'changePassword'], 'account.password.change');
        $router->post('/account/sessions/revoke', [ProfileController::class, 'revokeOtherSessions'], 'account.sessions.revoke');
        $router->get('/account/export', [ProfileController::class, 'exportData'], 'account.export');
        $router->post('/account/delete', [ProfileController::class, 'deleteAccount'], 'account.delete');
        $router->post('/account/passkeys/{id:\d+}/delete', [ProfileController::class, 'deletePasskey'], 'account.passkey.delete');

        // --- WebAuthn (JSON) ------------------------------------------------
        $router->post('/api/passkeys/register/options', [PasskeyController::class, 'registrationOptions'], 'api.passkeys.register_options', false);
        $router->post('/api/passkeys/register', [PasskeyController::class, 'register'], 'api.passkeys.register', false);
        $router->post('/api/passkeys/login/options', [PasskeyController::class, 'authenticationOptions'], 'api.passkeys.login_options', false);
        $router->post('/api/passkeys/login', [PasskeyController::class, 'authenticate'], 'api.passkeys.login', false);

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
        $router->post(
            '/admin/diagnostics/rate-limits/clear',
            [AdminDiagnosticsController::class, 'clearRateLimits'],
            'admin.diagnostics.rate_limits_clear'
        );

        $router->get('/admin/backups', [AdminBackupController::class, 'index'], 'admin.backups');
        $router->post('/admin/backups/create', [AdminBackupController::class, 'create'], 'admin.backups.create');
        $router->post('/admin/backups/{id}/restore', [AdminBackupController::class, 'restore'], 'admin.backups.restore');
        $router->post('/admin/backups/{id}/delete', [AdminBackupController::class, 'delete'], 'admin.backups.delete');
        $router->get('/admin/backups/{id}/download', [AdminBackupController::class, 'download'], 'admin.backups.download');
        $router->get('/admin/backups/{id}/verify', [AdminBackupController::class, 'verify'], 'admin.backups.verify');

        $router->get('/admin/updates', [AdminUpdateController::class, 'index'], 'admin.updates');
        $router->post('/admin/updates/check', [AdminUpdateController::class, 'check'], 'admin.updates.check');
        $router->post('/admin/updates/install', [AdminUpdateController::class, 'install'], 'admin.updates.install');

        $router->get('/admin/content', [AdminContentController::class, 'index'], 'admin.content');
        $router->post('/admin/content', [AdminContentController::class, 'create'], 'admin.content.create');
        $router->get('/admin/content/{id:\d+}', [AdminContentController::class, 'edit'], 'admin.content.edit');
        $router->post('/admin/content/{id:\d+}', [AdminContentController::class, 'save'], 'admin.content.save');
        $router->post('/admin/content/{id:\d+}/delete', [AdminContentController::class, 'delete'], 'admin.content.delete');

        $router->get('/admin/media', [AdminMediaController::class, 'index'], 'admin.media');
        $router->post('/admin/media', [AdminMediaController::class, 'upload'], 'admin.media.upload');
        $router->get('/admin/media/{id:\d+}', [AdminMediaController::class, 'edit'], 'admin.media.edit');
        $router->post('/admin/media/{id:\d+}', [AdminMediaController::class, 'save'], 'admin.media.save');
        $router->post('/admin/media/{id:\d+}/delete', [AdminMediaController::class, 'delete'], 'admin.media.delete');

        $router->post('/admin/maintenance', [AdminMaintenanceController::class, 'toggle'], 'admin.maintenance.toggle');

        // --- API technique ------------------------------------------------
        $router->get('/api/version', [ApiController::class, 'version'], 'api.version', false);
        $router->get('/api/health', [ApiController::class, 'health'], 'api.health', false);

        // --- Boîte e-mail de test (transport factice uniquement) -------------
        $router->get('/api/dev/mailbox', [DevMailboxController::class, 'index'], 'dev.mailbox', false);
        $router->post('/api/dev/mailbox/purge', [DevMailboxController::class, 'purge'], 'dev.mailbox.purge', false);

        // --- Pages éditoriales (attrape-tout, déclaré en dernier) ------------
        $router->get('/{slug:[a-z0-9][a-z0-9-]*}', [PageController::class, 'show'], 'page.show');
    }
}
