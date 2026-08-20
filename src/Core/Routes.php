<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Controller\Admin\AdminBackupController;
use SecondStay\Controller\Admin\AdminBookingController;
use SecondStay\Controller\Admin\AdminDashboardController;
use SecondStay\Controller\Admin\AdminDiagnosticsController;
use SecondStay\Controller\Admin\AdminLogController;
use SecondStay\Controller\Admin\AdminMaintenanceController;
use SecondStay\Controller\Admin\AdminPaymentController;
use SecondStay\Controller\Admin\AdminPricingController;
use SecondStay\Controller\Admin\AdminSettingsController;
use SecondStay\Controller\Admin\AdminUpdateController;
use SecondStay\Controller\Admin\AdminUserController;
use SecondStay\Controller\Account\PasskeyController;
use SecondStay\Controller\Account\PushController;
use SecondStay\Controller\Account\ProfileController;
use SecondStay\Controller\AccountController;
use SecondStay\Controller\ApiController;
use SecondStay\Controller\AuthController;
use SecondStay\Controller\BookingController;
use SecondStay\Controller\DevMailboxController;
use SecondStay\Controller\Admin\AdminContentController;
use SecondStay\Controller\Admin\AdminMediaController;
use SecondStay\Controller\InstallController;
use SecondStay\Controller\MediaController;
use SecondStay\Controller\PageController;
use SecondStay\Controller\PaymentController;
use SecondStay\Controller\PwaController;
use SecondStay\Controller\QuoteController;
use SecondStay\Controller\SeoController;
use SecondStay\Controller\WebhookController;

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

        // --- Application installable ----------------------------------------
        // Le service worker et les icônes vivent à la racine : c'est la
        // condition pour que le périmètre du service worker couvre le site.
        $router->get('/manifest.webmanifest', [PwaController::class, 'manifest'], 'pwa.manifest', false);
        $router->get('/sw.js', [PwaController::class, 'serviceWorker'], 'pwa.service_worker', false);
        $router->get(
            '/icon-{size:192|512}.png',
            [PwaController::class, 'icon'],
            'pwa.icon',
            false
        );
        $router->get(
            '/icon-{maskable:maskable}-{size:192|512}.png',
            [PwaController::class, 'icon'],
            'pwa.icon_maskable',
            false
        );
        $router->get('/offline', [PwaController::class, 'offline'], 'pwa.offline');

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
        $router->post('/account/notifications', [ProfileController::class, 'saveNotificationPreferences'], 'account.notifications.save');
        $router->post('/account/notifications/test', [ProfileController::class, 'sendTestNotification'], 'account.notifications.test');

        // --- WebAuthn (JSON) ------------------------------------------------
        $router->post('/api/passkeys/register/options', [PasskeyController::class, 'registrationOptions'], 'api.passkeys.register_options', false);
        $router->post('/api/passkeys/register', [PasskeyController::class, 'register'], 'api.passkeys.register', false);
        $router->post('/api/passkeys/login/options', [PasskeyController::class, 'authenticationOptions'], 'api.passkeys.login_options', false);
        $router->post('/api/passkeys/login', [PasskeyController::class, 'authenticate'], 'api.passkeys.login', false);

        // --- Réservation ------------------------------------------------------
        $router->get('/booking', [BookingController::class, 'summary'], 'booking.summary');
        $router->post('/booking', [BookingController::class, 'summary'], 'booking.summary.submit');
        $router->post('/booking/hold', [BookingController::class, 'hold'], 'booking.hold');
        $router->get('/booking/finalise', [BookingController::class, 'finalise'], 'booking.finalise');
        $router->post('/booking/finalise', [BookingController::class, 'submit'], 'booking.submit');
        $router->post('/booking/waitlist', [BookingController::class, 'joinWaitlist'], 'booking.waitlist');
        $router->get(
            '/booking/{reference:[A-Za-z0-9-]{8,9}}',
            [BookingController::class, 'show'],
            'booking.show'
        );

        // --- Paiement ---------------------------------------------------------
        $router->post('/payment/{id:\d+}/start', [PaymentController::class, 'start'], 'payment.start');
        $router->get('/payment/{id:\d+}/return', [PaymentController::class, 'returnFromProvider'], 'payment.return');
        $router->get('/payment/{id:\d+}/transfer', [PaymentController::class, 'transfer'], 'payment.transfer');
        $router->get('/payment/{id:\d+}/epc.svg', [PaymentController::class, 'epcQr'], 'payment.epc');

        // Notification fournisseur : hors langue, authentifiée par relecture
        // de l'état chez le fournisseur, donc exemptée de CSRF (Kernel).
        $router->post('/webhook/payment', [WebhookController::class, 'payment'], 'payment.webhook', false);

        // --- Devis en direct ------------------------------------------------
        $router->get('/api/quote', [QuoteController::class, 'quote'], 'api.quote', false);

        // --- Notifications push ------------------------------------------
        $router->get('/api/push/key', [PushController::class, 'publicKey'], 'api.push.key', false);
        $router->post('/api/push/subscribe', [PushController::class, 'subscribe'], 'api.push.subscribe', false);
        $router->post('/api/push/unsubscribe', [PushController::class, 'unsubscribe'], 'api.push.unsubscribe', false);

        // --- Administration --------------------------------------------------
        $router->get('/admin', [AdminDashboardController::class, 'index'], 'admin.dashboard');

        $router->get('/admin/settings', [AdminSettingsController::class, 'index'], 'admin.settings');
        $router->post('/admin/settings', [AdminSettingsController::class, 'save'], 'admin.settings.save');

        $router->get('/admin/users', [AdminUserController::class, 'index'], 'admin.users');
        $router->post('/admin/users', [AdminUserController::class, 'create'], 'admin.users.create');
        $router->post('/admin/users/{id:\d+}/role', [AdminUserController::class, 'changeRole'], 'admin.users.role');
        $router->post('/admin/users/{id:\d+}/delete', [AdminUserController::class, 'delete'], 'admin.users.delete');

        $router->get('/admin/bookings', [AdminBookingController::class, 'index'], 'admin.bookings');
        $router->get('/admin/bookings/{id:\d+}', [AdminBookingController::class, 'show'], 'admin.bookings.show');
        $router->post(
            '/admin/bookings/{id:\d+}/status',
            [AdminBookingController::class, 'transition'],
            'admin.bookings.status'
        );
        $router->post('/admin/promos', [AdminBookingController::class, 'createPromo'], 'admin.promos.create');
        $router->post(
            '/admin/promos/{id:\d+}/delete',
            [AdminBookingController::class, 'deletePromo'],
            'admin.promos.delete'
        );

        $router->get('/admin/pricing', [AdminPricingController::class, 'index'], 'admin.pricing');
        $router->post('/admin/pricing/rates', [AdminPricingController::class, 'saveRates'], 'admin.pricing.rates');
        $router->post('/admin/pricing/blocks', [AdminPricingController::class, 'createBlock'], 'admin.pricing.block_create');
        $router->post(
            '/admin/pricing/blocks/{id:\d+}/delete',
            [AdminPricingController::class, 'deleteBlock'],
            'admin.pricing.block_delete'
        );

        $router->get('/admin/logs', [AdminLogController::class, 'index'], 'admin.logs');
        $router->post('/admin/logs/purge', [AdminLogController::class, 'purge'], 'admin.logs.purge');
        $router->get('/admin/audit', [AdminLogController::class, 'auditTrail'], 'admin.audit');

        $router->get('/admin/diagnostics', [AdminDiagnosticsController::class, 'index'], 'admin.diagnostics');
        $router->post(
            '/admin/diagnostics/rate-limits/clear',
            [AdminDiagnosticsController::class, 'clearRateLimits'],
            'admin.diagnostics.rate_limits_clear'
        );
        $router->post(
            '/admin/diagnostics/push-keys',
            [AdminDiagnosticsController::class, 'generatePushKeys'],
            'admin.diagnostics.push_keys'
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

        // --- Paiements ------------------------------------------------------
        $router->get('/admin/payments', [AdminPaymentController::class, 'index'], 'admin.payments');
        $router->post(
            '/admin/bookings/{id:\d+}/schedule',
            [AdminPaymentController::class, 'schedule'],
            'admin.payments.schedule'
        );
        $router->post('/admin/payments/{id:\d+}/record', [AdminPaymentController::class, 'record'], 'admin.payments.record');
        $router->post('/admin/payments/{id:\d+}/refund', [AdminPaymentController::class, 'refund'], 'admin.payments.refund');
        $router->post('/admin/payments/{id:\d+}/hold', [AdminPaymentController::class, 'hold'], 'admin.payments.hold');

        $router->post('/admin/maintenance', [AdminMaintenanceController::class, 'toggle'], 'admin.maintenance.toggle');

        // --- API technique ------------------------------------------------
        $router->get('/api/version', [ApiController::class, 'version'], 'api.version', false);
        $router->get('/api/health', [ApiController::class, 'health'], 'api.health', false);

        // --- Boîte e-mail de test (transport factice uniquement) -------------
        $router->get('/api/dev/mailbox', [DevMailboxController::class, 'index'], 'dev.mailbox', false);
        $router->post('/api/dev/mailbox/purge', [DevMailboxController::class, 'purge'], 'dev.mailbox.purge', false);
        $router->get('/api/dev/notifications', [DevMailboxController::class, 'notifications'], 'dev.notifications', false);
        $router->get('/api/dev/payments', [DevMailboxController::class, 'payments'], 'dev.payments', false);
        $router->post('/api/dev/payments/settle', [DevMailboxController::class, 'settlePayment'], 'dev.payments.settle', false);

        // --- Pages éditoriales (attrape-tout, déclaré en dernier) ------------
        $router->get('/{slug:[a-z0-9][a-z0-9-]*}', [PageController::class, 'show'], 'page.show');
    }
}
