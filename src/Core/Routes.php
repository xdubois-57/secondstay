<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Controller\Admin\AdminBackupController;
use SecondStay\Controller\Admin\AdminBookingController;
use SecondStay\Controller\Admin\AdminDashboardController;
use SecondStay\Controller\Admin\AdminComplianceController;
use SecondStay\Controller\Admin\AdminDiagnosticsController;
use SecondStay\Controller\Admin\AdminDisputeController;
use SecondStay\Controller\Admin\AdminReportController;
use SecondStay\Controller\Admin\AdminPoliceController;
use SecondStay\Controller\Admin\AdminTaxController;
use SecondStay\Controller\Admin\AdminLocalContentController;
use SecondStay\Controller\Admin\AdminLogController;
use SecondStay\Controller\Admin\AdminMaintenanceController;
use SecondStay\Controller\Admin\AdminDocumentController;
use SecondStay\Controller\Admin\AdminIncidentController;
use SecondStay\Controller\Admin\AdminInspectionController;
use SecondStay\Controller\Admin\AdminMailboxController;
use SecondStay\Controller\Admin\AdminOperationsController;
use SecondStay\Controller\Admin\AdminStayController;
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
use SecondStay\Controller\InspectionController;
use SecondStay\Controller\InstallController;
use SecondStay\Controller\MediaController;
use SecondStay\Controller\PageController;
use SecondStay\Controller\CalendarController;
use SecondStay\Controller\StayController;
use SecondStay\Controller\StayInfoController;
use SecondStay\Controller\DocumentController;
use SecondStay\Controller\PaymentController;
use SecondStay\Controller\PwaController;
use SecondStay\Controller\SchedulerController;
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
        $router->post(
            '/account/forgot-password',
            [AccountController::class, 'forgotPassword'],
            'account.forgot.submit'
        );
        $router->get('/account/reset', [AccountController::class, 'showResetPassword'], 'account.reset');
        $router->post('/account/reset', [AccountController::class, 'resetPassword'], 'account.reset.submit');

        $router->get('/account', [ProfileController::class, 'show'], 'account.profile', access: Access::Authenticated);
        $router->post(
            '/account/profile',
            [ProfileController::class, 'updateProfile'],
            'account.profile.save',
            access: Access::Authenticated
        );
        $router->post(
            '/account/password',
            [ProfileController::class, 'changePassword'],
            'account.password.change',
            access: Access::Authenticated
        );
        $router->post(
            '/account/sessions/revoke',
            [ProfileController::class, 'revokeOtherSessions'],
            'account.sessions.revoke',
            access: Access::Authenticated
        );
        $router->get(
            '/account/export',
            [ProfileController::class, 'exportData'],
            'account.export',
            access: Access::Authenticated
        );
        $router->post(
            '/account/delete',
            [ProfileController::class, 'deleteAccount'],
            'account.delete',
            access: Access::Authenticated
        );
        $router->post(
            '/account/passkeys/{id:\d+}/delete',
            [ProfileController::class, 'deletePasskey'],
            'account.passkey.delete',
            access: Access::Authenticated
        );
        $router->post(
            '/account/notifications',
            [ProfileController::class, 'saveNotificationPreferences'],
            'account.notifications.save',
            access: Access::Authenticated
        );
        $router->post(
            '/account/notifications/test',
            [ProfileController::class, 'sendTestNotification'],
            'account.notifications.test',
            access: Access::Authenticated
        );

        // --- WebAuthn (JSON) ------------------------------------------------
        $router->post(
            '/api/passkeys/register/options',
            [PasskeyController::class, 'registrationOptions'],
            'api.passkeys.register_options',
            false,
            access: Access::Authenticated
        );
        $router->post(
            '/api/passkeys/register',
            [PasskeyController::class, 'register'],
            'api.passkeys.register',
            false,
            access: Access::Authenticated
        );
        $router->post(
            '/api/passkeys/login/options',
            [PasskeyController::class, 'authenticationOptions'],
            'api.passkeys.login_options',
            false
        );
        $router->post('/api/passkeys/login', [PasskeyController::class, 'authenticate'], 'api.passkeys.login', false);

        // --- Réservation ------------------------------------------------------
        $router->get('/booking', [BookingController::class, 'summary'], 'booking.summary');
        $router->post('/booking', [BookingController::class, 'summary'], 'booking.summary.submit');
        $router->post('/booking/hold', [BookingController::class, 'hold'], 'booking.hold');
        $router->get('/booking/finalise', [BookingController::class, 'finalise'], 'booking.finalise');
        $router->post(
            '/booking/finalise',
            [BookingController::class, 'submit'],
            'booking.submit',
            access: Access::Authenticated
        );
        $router->post('/booking/waitlist', [BookingController::class, 'joinWaitlist'], 'booking.waitlist');
        $router->get(
            '/booking/{reference:[A-Za-z0-9-]{8,9}}',
            [BookingController::class, 'show'],
            'booking.show',
            access: Access::Authenticated
        );

        // --- Mon séjour -----------------------------------------------------------
        // Ces pages sont les seules conçues pour fonctionner hors ligne : elles
        // ne portent ni montant, ni document, ni écriture.
        $router->get(
            '/stay/{reference:[A-Za-z0-9-]{8,9}}',
            [StayController::class, 'show'],
            'stay.show',
            access: Access::Authenticated
        );
        $router->post(
            '/stay/{reference:[A-Za-z0-9-]{8,9}}/guest',
            [StayController::class, 'issueGuestLink'],
            'stay.guest.issue',
            access: Access::Authenticated
        );
        $router->post(
            '/stay/guest/{id:\d+}/revoke',
            [StayController::class, 'revokeGuestLink'],
            'stay.guest.revoke',
            access: Access::Authenticated
        );
        // Lien invité : localisé, sans compte, adresse stable pour un QR collé
        // dans le logement (SPECIFICATIONS.md §47).
        $router->get('/guest/{token:[a-f0-9]{64}}', [StayController::class, 'guest'], 'stay.guest');

        // QR physiques : une adresse par bloc du livret, stable et dérivée du
        // seul code du bloc — l'autocollant ne se met pas à jour. La page
        // n'existe que si le propriétaire l'a explicitement rendue publique
        // (SPECIFICATIONS.md §47).
        $router->get('/info/{code:[a-z]{4,32}}', [StayInfoController::class, 'show'], 'stay.info');

        // --- États des lieux --------------------------------------------------
        // Ces pages écrivent — un constat, une photo : elles ne sont donc
        // jamais servies depuis le cache (SPECIFICATIONS.md §53).
        $router->get(
            '/stay/{reference:[A-Za-z0-9-]{8,9}}/inspection/{kind:checkin|checkout}',
            [InspectionController::class, 'show'],
            'inspection.show',
            access: Access::Authenticated
        );
        $router->post(
            '/stay/{reference:[A-Za-z0-9-]{8,9}}/inspection/{kind:checkin|checkout}/zone',
            [InspectionController::class, 'saveEntry'],
            'inspection.entry',
            access: Access::Authenticated
        );
        $router->post(
            '/stay/{reference:[A-Za-z0-9-]{8,9}}/inspection/{kind:checkin|checkout}/complete',
            [InspectionController::class, 'complete'],
            'inspection.complete',
            access: Access::Authenticated
        );
        $router->post(
            '/stay/{reference:[A-Za-z0-9-]{8,9}}/inspection/{kind:checkin|checkout}/incident',
            [InspectionController::class, 'raiseIncident'],
            'inspection.incident',
            access: Access::Authenticated
        );

        // --- Calendriers privés -------------------------------------------------
        // Hors langue et hors session : un agenda tiers ne présente qu'un
        // jeton, et l'adresse doit rester stable une fois abonnée.
        $router->get('/calendar/{token:[a-f0-9]{64}}.ics', [CalendarController::class, 'feed'], 'calendar.feed', false);

        $router->post(
            '/booking/{reference:[A-Za-z0-9-]{8,9}}/calendar',
            [BookingController::class, 'calendarLink'],
            'booking.calendar',
            access: Access::Authenticated
        );

        // --- Documents et contrat ---------------------------------------------
        $router->get(
            '/document/{id:\d+}',
            [DocumentController::class, 'download'],
            'document.download',
            access: Access::Authenticated
        );
        $router->get(
            '/booking/{reference:[A-Za-z0-9-]{8,9}}/contract',
            [DocumentController::class, 'contract'],
            'contract.show',
            access: Access::Authenticated
        );
        $router->post(
            '/booking/{reference:[A-Za-z0-9-]{8,9}}/contract',
            [DocumentController::class, 'acceptContract'],
            'contract.accept',
            access: Access::Authenticated
        );

        // --- Paiement ---------------------------------------------------------
        $router->post(
            '/payment/{id:\d+}/start',
            [PaymentController::class, 'start'],
            'payment.start',
            access: Access::Authenticated
        );
        $router->get(
            '/payment/{id:\d+}/return',
            [PaymentController::class, 'returnFromProvider'],
            'payment.return',
            access: Access::Authenticated
        );
        $router->get(
            '/payment/{id:\d+}/transfer',
            [PaymentController::class, 'transfer'],
            'payment.transfer',
            access: Access::Authenticated
        );
        $router->get(
            '/payment/{id:\d+}/epc.svg',
            [PaymentController::class, 'epcQr'],
            'payment.epc',
            access: Access::Authenticated
        );

        // Notification fournisseur : hors langue, authentifiée par relecture
        // de l'état chez le fournisseur, donc exemptée de CSRF (Kernel).
        $router->post('/webhook/payment', [WebhookController::class, 'payment'], 'payment.webhook', false);

        // Déclenchement HTTP du planificateur, pour les hébergements dont le
        // cron n'appelle que des URLs. Fermé tant qu'aucun jeton n'est
        // enregistré : sans jeton, la route répond 404 (SchedulerController).
        $router->get('/tasks/run', [SchedulerController::class, 'run'], 'scheduler.run', false);

        // --- Devis en direct ------------------------------------------------
        $router->get('/api/quote', [QuoteController::class, 'quote'], 'api.quote', false);

        // --- Notifications push ------------------------------------------
        $router->get('/api/push/key', [PushController::class, 'publicKey'], 'api.push.key', false);
        $router->post(
            '/api/push/subscribe',
            [PushController::class, 'subscribe'],
            'api.push.subscribe',
            false,
            access: Access::Authenticated
        );
        $router->post(
            '/api/push/unsubscribe',
            [PushController::class, 'unsubscribe'],
            'api.push.unsubscribe',
            false,
            access: Access::Authenticated
        );

        // --- Administration --------------------------------------------------
        $router->get(
            '/admin',
            [AdminDashboardController::class, 'index'],
            'admin.dashboard',
            access: Access::Operational
        );

        $router->get(
            '/admin/settings',
            [AdminSettingsController::class, 'index'],
            'admin.settings',
            access: Access::Administrator
        );
        $router->post(
            '/admin/settings',
            [AdminSettingsController::class, 'save'],
            'admin.settings.save',
            access: Access::Administrator
        );

        $router->get(
            '/admin/users',
            [AdminUserController::class, 'index'],
            'admin.users',
            access: Access::Administrator
        );
        $router->post(
            '/admin/users',
            [AdminUserController::class, 'create'],
            'admin.users.create',
            access: Access::Administrator
        );
        $router->post(
            '/admin/users/{id:\d+}/role',
            [AdminUserController::class, 'changeRole'],
            'admin.users.role',
            access: Access::Administrator
        );
        $router->post(
            '/admin/users/{id:\d+}/delete',
            [AdminUserController::class, 'delete'],
            'admin.users.delete',
            access: Access::Administrator
        );

        $router->get(
            '/admin/bookings',
            [AdminBookingController::class, 'index'],
            'admin.bookings',
            access: Access::Operational
        );
        $router->get(
            '/admin/bookings/{id:\d+}',
            [AdminBookingController::class, 'show'],
            'admin.bookings.show',
            access: Access::Operational
        );
        $router->post(
            '/admin/bookings/{id:\d+}/status',
            [AdminBookingController::class, 'transition'],
            'admin.bookings.status',
            access: Access::Operational
        );
        $router->post(
            '/admin/promos',
            [AdminBookingController::class, 'createPromo'],
            'admin.promos.create',
            access: Access::Operational
        );
        $router->post(
            '/admin/promos/{id:\d+}/delete',
            [AdminBookingController::class, 'deletePromo'],
            'admin.promos.delete',
            access: Access::Operational
        );

        $router->get(
            '/admin/pricing',
            [AdminPricingController::class, 'index'],
            'admin.pricing',
            access: Access::Operational
        );
        $router->post(
            '/admin/pricing/rates',
            [AdminPricingController::class, 'saveRates'],
            'admin.pricing.rates',
            access: Access::Operational
        );
        $router->post(
            '/admin/pricing/blocks',
            [AdminPricingController::class, 'createBlock'],
            'admin.pricing.block_create',
            access: Access::Operational
        );
        $router->post(
            '/admin/pricing/blocks/{id:\d+}/delete',
            [AdminPricingController::class, 'deleteBlock'],
            'admin.pricing.block_delete',
            access: Access::Operational
        );

        $router->get('/admin/logs', [AdminLogController::class, 'index'], 'admin.logs', access: Access::Administrator);
        $router->post(
            '/admin/logs/purge',
            [AdminLogController::class, 'purge'],
            'admin.logs.purge',
            access: Access::Administrator
        );
        $router->get(
            '/admin/audit',
            [AdminLogController::class, 'auditTrail'],
            'admin.audit',
            access: Access::Administrator
        );

        $router->get(
            '/admin/diagnostics',
            [AdminDiagnosticsController::class, 'index'],
            'admin.diagnostics',
            access: Access::Administrator
        );
        $router->post(
            '/admin/diagnostics/rate-limits/clear',
            [AdminDiagnosticsController::class, 'clearRateLimits'],
            'admin.diagnostics.rate_limits_clear',
            access: Access::Administrator
        );
        $router->post(
            '/admin/diagnostics/push-keys',
            [AdminDiagnosticsController::class, 'generatePushKeys'],
            'admin.diagnostics.push_keys',
            access: Access::Administrator
        );

        $router->get(
            '/admin/backups',
            [AdminBackupController::class, 'index'],
            'admin.backups',
            access: Access::Administrator
        );
        $router->post(
            '/admin/backups/create',
            [AdminBackupController::class, 'create'],
            'admin.backups.create',
            access: Access::Administrator
        );
        $router->post(
            '/admin/backups/{id}/restore',
            [AdminBackupController::class, 'restore'],
            'admin.backups.restore',
            access: Access::Administrator
        );
        $router->post(
            '/admin/backups/{id}/delete',
            [AdminBackupController::class, 'delete'],
            'admin.backups.delete',
            access: Access::Administrator
        );
        $router->get(
            '/admin/backups/{id}/download',
            [AdminBackupController::class, 'download'],
            'admin.backups.download',
            access: Access::Administrator
        );
        $router->get(
            '/admin/backups/{id}/verify',
            [AdminBackupController::class, 'verify'],
            'admin.backups.verify',
            access: Access::Administrator
        );

        $router->get(
            '/admin/updates',
            [AdminUpdateController::class, 'index'],
            'admin.updates',
            access: Access::Administrator
        );
        $router->post(
            '/admin/updates/check',
            [AdminUpdateController::class, 'check'],
            'admin.updates.check',
            access: Access::Administrator
        );
        $router->post(
            '/admin/updates/install',
            [AdminUpdateController::class, 'install'],
            'admin.updates.install',
            access: Access::Administrator
        );

        $router->get(
            '/admin/content',
            [AdminContentController::class, 'index'],
            'admin.content',
            access: Access::Administrator
        );
        $router->post(
            '/admin/content',
            [AdminContentController::class, 'create'],
            'admin.content.create',
            access: Access::Administrator
        );
        $router->get(
            '/admin/content/{id:\d+}',
            [AdminContentController::class, 'edit'],
            'admin.content.edit',
            access: Access::Administrator
        );
        $router->post(
            '/admin/content/{id:\d+}',
            [AdminContentController::class, 'save'],
            'admin.content.save',
            access: Access::Administrator
        );
        $router->post(
            '/admin/content/{id:\d+}/delete',
            [AdminContentController::class, 'delete'],
            'admin.content.delete',
            access: Access::Administrator
        );

        $router->get(
            '/admin/media',
            [AdminMediaController::class, 'index'],
            'admin.media',
            access: Access::Administrator
        );
        $router->post(
            '/admin/media',
            [AdminMediaController::class, 'upload'],
            'admin.media.upload',
            access: Access::Administrator
        );
        $router->get(
            '/admin/media/{id:\d+}',
            [AdminMediaController::class, 'edit'],
            'admin.media.edit',
            access: Access::Administrator
        );
        $router->post(
            '/admin/media/{id:\d+}',
            [AdminMediaController::class, 'save'],
            'admin.media.save',
            access: Access::Administrator
        );
        $router->post(
            '/admin/media/{id:\d+}/delete',
            [AdminMediaController::class, 'delete'],
            'admin.media.delete',
            access: Access::Administrator
        );

        // --- Livret d'accueil -----------------------------------------------
        $router->get('/admin/stay', [AdminStayController::class, 'index'], 'admin.stay', access: Access::Operational);
        $router->post(
            '/admin/stay',
            [AdminStayController::class, 'save'],
            'admin.stay.save',
            access: Access::Operational
        );
        $router->post(
            '/admin/stay/secrets',
            [AdminStayController::class, 'saveSecrets'],
            'admin.stay.secrets',
            access: Access::Administrator
        );

        // --- Exploitation ---------------------------------------------------
        $router->get(
            '/admin/operations',
            [AdminOperationsController::class, 'index'],
            'admin.operations',
            access: Access::Operational
        );
        $router->post(
            '/admin/bookings/{id:\d+}/manager',
            [AdminOperationsController::class, 'assignManager'],
            'admin.operations.manager',
            access: Access::Administrator
        );
        $router->post(
            '/admin/bookings/{id:\d+}/task',
            [AdminOperationsController::class, 'toggleTask'],
            'admin.operations.task',
            access: Access::Operational
        );
        $router->post(
            '/admin/tasks/run',
            [AdminOperationsController::class, 'runTask'],
            'admin.tasks.run',
            access: Access::Administrator
        );
        $router->post(
            '/admin/calendars',
            [AdminOperationsController::class, 'issueCalendar'],
            'admin.calendars.issue',
            access: Access::Operational
        );
        $router->post(
            '/admin/calendars/{id:\d+}/revoke',
            [AdminOperationsController::class, 'revokeCalendar'],
            'admin.calendars.revoke',
            access: Access::Operational
        );

        // --- États des lieux et incidents -----------------------------------
        $router->get(
            '/admin/inspections',
            [AdminInspectionController::class, 'index'],
            'admin.inspections',
            access: Access::Operational
        );
        $router->post(
            '/admin/inspections',
            [AdminInspectionController::class, 'saveZone'],
            'admin.inspections.zone',
            access: Access::Operational
        );
        $router->post(
            '/admin/inspections/seed',
            [AdminInspectionController::class, 'seed'],
            'admin.inspections.seed',
            access: Access::Administrator
        );
        $router->post(
            '/admin/inspections/{id:\d+}/reference',
            [AdminInspectionController::class, 'uploadReference'],
            'admin.inspections.reference',
            access: Access::Operational
        );
        $router->get(
            '/admin/bookings/{id:\d+}/inspections',
            [AdminInspectionController::class, 'forBooking'],
            'admin.bookings.inspections',
            access: Access::Operational
        );

        $router->get(
            '/admin/incidents',
            [AdminIncidentController::class, 'index'],
            'admin.incidents',
            access: Access::Operational
        );
        $router->post(
            '/admin/incidents',
            [AdminIncidentController::class, 'create'],
            'admin.incidents.create',
            access: Access::Operational
        );
        $router->get(
            '/admin/incidents/{id:\d+}',
            [AdminIncidentController::class, 'show'],
            'admin.incidents.show',
            access: Access::Operational
        );
        $router->post(
            '/admin/incidents/{id:\d+}/status',
            [AdminIncidentController::class, 'transition'],
            'admin.incidents.status',
            access: Access::Operational
        );
        $router->post(
            '/admin/incidents/{id:\d+}/assign',
            [AdminIncidentController::class, 'assign'],
            'admin.incidents.assign',
            access: Access::Operational
        );
        $router->post(
            '/admin/incidents/{id:\d+}/comment',
            [AdminIncidentController::class, 'comment'],
            'admin.incidents.comment',
            access: Access::Operational
        );
        $router->post(
            '/admin/incidents/{id:\d+}/photo',
            [AdminIncidentController::class, 'uploadPhoto'],
            'admin.incidents.photo',
            access: Access::Operational
        );

        // --- Conformité France ------------------------------------------------
        $router->get(
            '/admin/compliance',
            [AdminComplianceController::class, 'index'],
            'admin.compliance',
            access: Access::Operational
        );
        $router->post(
            '/admin/compliance/{topic:[a-z_]+}',
            [AdminComplianceController::class, 'save'],
            'admin.compliance.save',
            access: Access::Operational
        );
        $router->post(
            '/admin/compliance/{topic:[a-z_]+}/evidence',
            [AdminComplianceController::class, 'uploadEvidence'],
            'admin.compliance.evidence',
            access: Access::Operational
        );
        $router->post(
            '/admin/legal/publish',
            [AdminComplianceController::class, 'publishLegal'],
            'admin.legal.publish',
            access: Access::Administrator
        );

        // --- Taxe de séjour ---------------------------------------------------
        $router->get('/admin/tax', [AdminTaxController::class, 'index'], 'admin.tax', access: Access::Operational);
        $router->post(
            '/admin/tax',
            [AdminTaxController::class, 'create'],
            'admin.tax.create',
            access: Access::Administrator
        );
        $router->post(
            '/admin/tax/{id:\d+}/delete',
            [AdminTaxController::class, 'delete'],
            'admin.tax.delete',
            access: Access::Administrator
        );

        // --- Fiche de police et rétention -------------------------------------
        $router->get(
            '/admin/police',
            [AdminPoliceController::class, 'index'],
            'admin.police',
            access: Access::Operational
        );
        $router->get(
            '/admin/police/{id:\d+}',
            [AdminPoliceController::class, 'edit'],
            'admin.police.edit',
            access: Access::Operational
        );
        $router->post(
            '/admin/police/{id:\d+}',
            [AdminPoliceController::class, 'save'],
            'admin.police.save',
            access: Access::Operational
        );
        $router->post(
            '/admin/police/{id:\d+}/delete',
            [AdminPoliceController::class, 'delete'],
            'admin.police.delete',
            access: Access::Administrator
        );
        $router->post(
            '/admin/retention/purge',
            [AdminPoliceController::class, 'purge'],
            'admin.retention.purge',
            access: Access::Administrator
        );

        // --- Contenu local généré ---------------------------------------------
        $router->get(
            '/admin/local',
            [AdminLocalContentController::class, 'index'],
            'admin.local',
            access: Access::Operational
        );
        $router->post(
            '/admin/local/sources',
            [AdminLocalContentController::class, 'addSource'],
            'admin.local.source_add',
            access: Access::Operational
        );
        $router->post(
            '/admin/local/sources/{id:\d+}/toggle',
            [AdminLocalContentController::class, 'toggleSource'],
            'admin.local.source_toggle',
            access: Access::Operational
        );
        $router->post(
            '/admin/local/sources/{id:\d+}/delete',
            [AdminLocalContentController::class, 'deleteSource'],
            'admin.local.source_delete',
            access: Access::Operational
        );
        $router->post(
            '/admin/local/prompt',
            [AdminLocalContentController::class, 'savePrompt'],
            'admin.local.prompt',
            access: Access::Operational
        );
        $router->post(
            '/admin/local/prompt/suggest',
            [AdminLocalContentController::class, 'suggestPrompt'],
            'admin.local.suggest',
            access: Access::Operational
        );
        $router->post(
            '/admin/local/test',
            [AdminLocalContentController::class, 'test'],
            'admin.local.test',
            access: Access::Operational
        );
        $router->post(
            '/admin/local/refresh',
            [AdminLocalContentController::class, 'refresh'],
            'admin.local.refresh',
            access: Access::Operational
        );

        // --- Litiges ------------------------------------------------------------
        $router->get(
            '/admin/disputes',
            [AdminDisputeController::class, 'index'],
            'admin.disputes',
            access: Access::Operational
        );
        $router->get(
            '/admin/disputes/{id:\d+}',
            [AdminDisputeController::class, 'show'],
            'admin.disputes.show',
            access: Access::Operational
        );
        $router->post(
            '/admin/bookings/{id:\d+}/dispute',
            [AdminDisputeController::class, 'open'],
            'admin.disputes.open',
            access: Access::Operational
        );
        $router->post(
            '/admin/disputes/{id:\d+}/status',
            [AdminDisputeController::class, 'transition'],
            'admin.disputes.status',
            access: Access::Operational
        );
        $router->post(
            '/admin/disputes/{id:\d+}/comment',
            [AdminDisputeController::class, 'comment'],
            'admin.disputes.comment',
            access: Access::Operational
        );

        // --- Reporting ------------------------------------------------------------
        $router->get(
            '/admin/reports',
            [AdminReportController::class, 'index'],
            'admin.reports',
            access: Access::Operational
        );
        $router->get(
            '/admin/reports/export.xlsx',
            [AdminReportController::class, 'export'],
            'admin.reports.export',
            access: Access::Operational
        );

        // --- Calendriers externes -------------------------------------------------
        $router->post(
            '/admin/calendars/imports',
            [AdminOperationsController::class, 'addCalendarImport'],
            'admin.imports.add',
            access: Access::Operational
        );
        $router->post(
            '/admin/calendars/imports/{id:\d+}/delete',
            [AdminOperationsController::class, 'deleteCalendarImport'],
            'admin.imports.delete',
            access: Access::Operational
        );
        $router->post(
            '/admin/calendars/imports/sync',
            [AdminOperationsController::class, 'syncCalendarImports'],
            'admin.imports.sync',
            access: Access::Operational
        );

        // --- Documents ------------------------------------------------------
        $router->get(
            '/admin/documents',
            [AdminDocumentController::class, 'index'],
            'admin.documents',
            access: Access::Operational
        );
        $router->post(
            '/admin/bookings/{id:\d+}/documents',
            [AdminDocumentController::class, 'upload'],
            'admin.documents.upload',
            access: Access::Operational
        );
        $router->post(
            '/admin/bookings/{id:\d+}/contract',
            [AdminDocumentController::class, 'generateContract'],
            'admin.contract.generate',
            access: Access::Operational
        );
        $router->post(
            '/admin/documents/{id:\d+}/kind',
            [AdminDocumentController::class, 'reclassify'],
            'admin.documents.reclassify',
            access: Access::Operational
        );
        $router->post(
            '/admin/documents/{id:\d+}/delete',
            [AdminDocumentController::class, 'delete'],
            'admin.documents.delete',
            access: Access::Administrator
        );

        // --- Courrier entrant -------------------------------------------------
        $router->get(
            '/admin/mailbox',
            [AdminMailboxController::class, 'index'],
            'admin.mailbox',
            access: Access::Operational
        );
        $router->get(
            '/admin/mailbox/{id:\d+}',
            [AdminMailboxController::class, 'show'],
            'admin.mailbox.show',
            access: Access::Operational
        );
        $router->post(
            '/admin/mailbox/sync',
            [AdminMailboxController::class, 'synchronise'],
            'admin.mailbox.sync',
            access: Access::Operational
        );
        $router->post(
            '/admin/mailbox/{id:\d+}/link',
            [AdminMailboxController::class, 'link'],
            'admin.mailbox.link',
            access: Access::Operational
        );

        // --- Paiements ------------------------------------------------------
        $router->get(
            '/admin/payments',
            [AdminPaymentController::class, 'index'],
            'admin.payments',
            access: Access::Operational
        );
        $router->post(
            '/admin/bookings/{id:\d+}/schedule',
            [AdminPaymentController::class, 'schedule'],
            'admin.payments.schedule',
            access: Access::Operational
        );
        $router->post(
            '/admin/payments/{id:\d+}/record',
            [AdminPaymentController::class, 'record'],
            'admin.payments.record',
            access: Access::Operational
        );
        $router->post(
            '/admin/payments/{id:\d+}/refund',
            [AdminPaymentController::class, 'refund'],
            'admin.payments.refund',
            access: Access::Operational
        );
        $router->post(
            '/admin/payments/{id:\d+}/hold',
            [AdminPaymentController::class, 'hold'],
            'admin.payments.hold',
            access: Access::Operational
        );

        $router->post(
            '/admin/maintenance',
            [AdminMaintenanceController::class, 'toggle'],
            'admin.maintenance.toggle',
            access: Access::Administrator
        );

        // --- API technique ------------------------------------------------
        $router->get('/api/version', [ApiController::class, 'version'], 'api.version', false);
        $router->get('/api/health', [ApiController::class, 'health'], 'api.health', false);

        // --- Boîte e-mail de test (transport factice uniquement) -------------
        $router->get('/api/dev/mailbox', [DevMailboxController::class, 'index'], 'dev.mailbox', false);
        $router->post('/api/dev/mailbox/purge', [DevMailboxController::class, 'purge'], 'dev.mailbox.purge', false);
        $router->get(
            '/api/dev/notifications',
            [DevMailboxController::class, 'notifications'],
            'dev.notifications',
            false
        );
        $router->get('/api/dev/payments', [DevMailboxController::class, 'payments'], 'dev.payments', false);
        $router->post(
            '/api/dev/payments/settle',
            [DevMailboxController::class, 'settlePayment'],
            'dev.payments.settle',
            false
        );
        // Dépôt d'un message dans la boîte factice : authentifié par le
        // fournisseur de test lui-même, donc exempté de CSRF comme un webhook.
        $router->post('/webhook/dev/inbox', [DevMailboxController::class, 'deliver'], 'dev.inbox.deliver', false);
        // Fixtures HTTP : mêmes règles que la boîte factice — la route
        // n'existe que si le fetcher de fixtures est activé.
        $router->post('/webhook/dev/http', [DevMailboxController::class, 'storeHttpFixture'], 'dev.http.store', false);
        $router->post(
            '/webhook/dev/http/purge',
            [DevMailboxController::class, 'purgeHttpFixtures'],
            'dev.http.purge',
            false
        );

        // --- Pages éditoriales (attrape-tout, déclaré en dernier) ------------
        $router->get('/{slug:[a-z0-9][a-z0-9-]*}', [PageController::class, 'show'], 'page.show');
    }
}
