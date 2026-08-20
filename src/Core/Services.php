<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Audit\AuditTrail;
use SecondStay\Availability\AvailabilityBlockRepository;
use SecondStay\Availability\AvailabilityService;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\BookingService;
use SecondStay\Booking\PromoCodeRepository;
use SecondStay\Booking\QuoteService;
use SecondStay\Booking\WaitlistRepository;
use SecondStay\Booking\StayRules;
use SecondStay\Pricing\PriceCalculator;
use SecondStay\Pricing\RateRepository;
use SecondStay\Auth\AccountService;
use SecondStay\Auth\AuthService;
use SecondStay\Auth\ConsentRepository;
use SecondStay\Auth\TokenRepository;
use SecondStay\Auth\WebAuthn\WebAuthnCredentialRepository;
use SecondStay\Auth\WebAuthn\WebAuthnService;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Auth\SessionRepository;
use SecondStay\Auth\UserRepository;
use SecondStay\Backup\BackupService;
use SecondStay\Content\ContentRepository;
use SecondStay\Content\ContentSeeder;
use SecondStay\Content\ContentService;
use SecondStay\Database\Database;
use SecondStay\Database\DatabaseConfig;
use SecondStay\Database\Migrator;
use SecondStay\Diagnostics\DiagnosticRunner;
use SecondStay\Diagnostics\MailDnsChecker;
use SecondStay\Diagnostics\MailboxDiagnostics;
use SecondStay\Diagnostics\NotificationDiagnostics;
use SecondStay\Http\CurlHttpFetcher;
use SecondStay\Http\HttpFetcher;
use SecondStay\Installer\InstallationState;
use SecondStay\Installer\Installer;
use SecondStay\Installer\RequirementChecker;
use SecondStay\I18n\Translator;
use SecondStay\Logging\Logger;
use SecondStay\Media\ImageProcessor;
use SecondStay\Media\MediaRepository;
use SecondStay\Mail\FakeMailTransport;
use SecondStay\Mail\MailAddress;
use SecondStay\Mail\MailRepository;
use SecondStay\Mail\MailService;
use SecondStay\Mail\MailTransport;
use SecondStay\Mail\SmtpMailTransport;
use SecondStay\Notification\NotificationPreferenceRepository;
use SecondStay\Notification\NotificationRepository;
use SecondStay\Notification\NotificationService;
use SecondStay\Pwa\IconGenerator;
use SecondStay\Pwa\ManifestBuilder;
use SecondStay\Push\FakePushProvider;
use SecondStay\Push\PushProvider;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Push\VapidKeyManager;
use SecondStay\Push\WebPushProvider;
use SecondStay\Media\MediaService;
use SecondStay\Seo\SeoBuilder;
use SecondStay\Support\HtmlSanitizer;
use SecondStay\Logging\LogLevel;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Security\Csrf;
use SecondStay\Security\Encryptor;
use SecondStay\Security\RateLimiter;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsRepository;
use SecondStay\Contract\ContractBuilder;
use SecondStay\Contract\ContractRepository;
use SecondStay\Contract\ContractService;
use SecondStay\Document\DocumentRepository;
use SecondStay\Document\DocumentService;
use SecondStay\Imap\FakeImapProvider;
use SecondStay\Imap\ImapClient;
use SecondStay\Imap\ImapProvider;
use SecondStay\Imap\InboundMailRepository;
use SecondStay\Imap\InboundMailService;
use SecondStay\Imap\MimeParser;
use SecondStay\I18n\Formatter;
use SecondStay\Imap\ReplyToken;
use SecondStay\Payment\FakePaymentProvider;
use SecondStay\Payment\MolliePaymentProvider;
use SecondStay\Payment\NullPaymentProvider;
use SecondStay\Payment\PaymentProvider;
use SecondStay\Payment\PaymentRepository;
use SecondStay\Payment\PaymentService;
use SecondStay\Payment\WebhookRepository;
use SecondStay\Settings\SettingsService;
use SecondStay\Tax\TouristTaxCalculator;
use SecondStay\Update\GitHubReleaseProvider;
use SecondStay\Update\ReleaseProvider;
use SecondStay\Update\UpdateService;
use Throwable;

/**
 * Enregistrement des services applicatifs dans le conteneur.
 *
 * Les services dépendant de la base sont paresseux : l'application doit rester
 * utilisable avant l'installation et pendant une panne de base.
 */
final class Services
{
    public static function register(Container $container, string $projectRoot, string $appVersion): void
    {
        $container->set(MaintenanceMode::class, static fn (Container $c): MaintenanceMode
            => new MaintenanceMode($c->get(Paths::class)->storage('maintenance.json')));

        $container->set(Logger::class, static function (Container $c): Logger {
            $config = $c->get(Config::class);
            $logger = new Logger(
                $c->get(Paths::class)->storage('logs'),
                LogLevel::fromString($config->string('logging.level', 'info'))
            );

            return $logger->withDatabase(self::optionalDatabase($c));
        });

        $container->set(InstallationState::class, static fn (Container $c): InstallationState
            => new InstallationState($c->get(Paths::class)));

        $container->set(RequirementChecker::class, static fn (Container $c): RequirementChecker
            => new RequirementChecker($c->get(Paths::class)));

        $container->set(Installer::class, static fn (Container $c): Installer
            => new Installer($c->get(Paths::class), $c->get(InstallationState::class)));

        $container->set(PasswordHasher::class, static fn (): PasswordHasher => new PasswordHasher());

        $container->set(SettingRegistry::class, static fn (): SettingRegistry => new SettingRegistry());

        $container->set(HttpFetcher::class, static fn (): HttpFetcher => new CurlHttpFetcher());

        $container->set(ReleaseProvider::class, static function (Container $c): ReleaseProvider {
            $repository = 'xdubois-57/secondstay';
            if ($c->has(SettingsService::class)) {
                try {
                    $configured = $c->get(SettingsService::class)->string('update.repository');
                    if ($configured !== '') {
                        $repository = $configured;
                    }
                } catch (Throwable) {
                    // Réglages indisponibles : on garde le dépôt officiel.
                }
            }

            return new GitHubReleaseProvider($c->get(HttpFetcher::class), $repository);
        });

        $container->set(Session::class, static function (Container $c): Session {
            $config = $c->get(Config::class);

            return new PhpSession(
                $config->string('security.session_name', 'secondstay_session'),
                $config->int('security.session_lifetime_minutes', 120),
                false,
            );
        });

        $container->set(Csrf::class, static fn (Container $c): Csrf => new Csrf($c->get(Session::class)));

        // ------------------------------------------------------------------
        // Services dépendant de la base de données.
        // ------------------------------------------------------------------

        $container->set(DatabaseConfig::class, static function (Container $c): DatabaseConfig {
            $config = $c->get(Config::class);

            return new DatabaseConfig(
                $config->string('database.host', '127.0.0.1'),
                $config->int('database.port', 3306),
                $config->string('database.name'),
                $config->string('database.user'),
                $config->string('database.password'),
                $config->string('database.charset', 'utf8mb4'),
            );
        });

        $container->set(Database::class, static fn (Container $c): Database
            => new Database($c->get(DatabaseConfig::class)));

        $container->set(Encryptor::class, static function (Container $c): Encryptor {
            $config = $c->get(Config::class);

            /** @var array<string, string> $keys */
            $keys = [];
            foreach ($config->array('security.encryption_keys') as $id => $key) {
                if (is_string($key) && $key !== '') {
                    $keys[(string) $id] = $key;
                }
            }

            $single = $config->string('security.encryption_key');
            if ($keys === [] && $single !== '') {
                $keys = ['k1' => $single];
            }

            $active = $config->string('security.active_encryption_key', 'k1');
            if (!isset($keys[$active])) {
                $active = (string) array_key_first($keys);
            }

            return new Encryptor($keys, $active);
        });

        // Le jeton d'adresse de réponse est dérivé de la clé de chiffrement
        // de l'installation : il n'ajoute donc aucun secret à gérer, et deux
        // installations ne signent jamais pareil.
        $container->set(ReplyToken::class, static fn (Container $c): ReplyToken => new ReplyToken(
            hash_hmac('sha256', 'reply-token', $c->get(Config::class)->string('security.encryption_key'))
        ));

        $container->set(SettingsRepository::class, static fn (Container $c): SettingsRepository
            => new SettingsRepository($c->get(Database::class)));

        $container->set(AuditTrail::class, static fn (Container $c): AuditTrail
            => new AuditTrail($c->get(Database::class), $c->get(Logger::class)->correlationId()));

        $container->set(SettingsService::class, static fn (Container $c): SettingsService => new SettingsService(
            $c->get(SettingRegistry::class),
            $c->get(SettingsRepository::class),
            $c->get(Encryptor::class),
            new \SecondStay\Settings\SettingValidator(),
            $c->get(AuditTrail::class),
        ));

        $container->set(UserRepository::class, static fn (Container $c): UserRepository
            => new UserRepository($c->get(Database::class)));

        $container->set(SessionRepository::class, static fn (Container $c): SessionRepository
            => new SessionRepository($c->get(Database::class)));

        $container->set(RateLimiter::class, static fn (Container $c): RateLimiter
            => new RateLimiter($c->get(Database::class)));

        $container->set(AuthService::class, static fn (Container $c): AuthService => new AuthService(
            $c->get(UserRepository::class),
            $c->get(SessionRepository::class),
            $c->get(Session::class),
            $c->get(PasswordHasher::class),
            $c->get(RateLimiter::class),
            $c->get(Logger::class),
            $c->get(AuditTrail::class),
            $c->get(Config::class)->int('security.session_lifetime_minutes', 120),
        ));

        $container->set(Migrator::class, static fn (Container $c): Migrator
            => new Migrator($c->get(Database::class), $c->get(Paths::class)->migrations()));

        $container->set(BackupService::class, static fn (Container $c): BackupService => new BackupService(
            $c->get(Database::class),
            $c->get(Paths::class),
            $c->get(MaintenanceMode::class),
            $appVersion,
            $c->get(AuditTrail::class),
        ));

        $container->set(UpdateService::class, static fn (Container $c): UpdateService => new UpdateService(
            $c->get(ReleaseProvider::class),
            $c->get(Paths::class),
            $c->get(Database::class),
            $c->get(BackupService::class),
            $c->get(MaintenanceMode::class),
            $c->get(Logger::class),
            $c->get(AuditTrail::class),
        ));

        $container->set(HtmlSanitizer::class, static fn (): HtmlSanitizer => new HtmlSanitizer());

        $container->set(ContentRepository::class, static fn (Container $c): ContentRepository
            => new ContentRepository($c->get(Database::class)));

        $container->set(ContentService::class, static fn (Container $c): ContentService => new ContentService(
            $c->get(ContentRepository::class),
            $c->get(SettingsService::class),
            $c->get(HtmlSanitizer::class),
            $c->get(AuditTrail::class),
        ));

        $container->set(ContentSeeder::class, static fn (Container $c): ContentSeeder => new ContentSeeder(
            $c->get(ContentRepository::class),
            $c->get(Translator::class),
            $c->get(Database::class),
        ));

        $container->set(ImageProcessor::class, static fn (): ImageProcessor => new ImageProcessor());

        $container->set(MediaRepository::class, static fn (Container $c): MediaRepository
            => new MediaRepository($c->get(Database::class)));

        $container->set(MediaService::class, static fn (Container $c): MediaService => new MediaService(
            $c->get(MediaRepository::class),
            $c->get(Paths::class),
            $c->get(ImageProcessor::class),
            $c->get(AuditTrail::class),
        ));

        $container->set(SeoBuilder::class, static fn (Container $c): SeoBuilder => new SeoBuilder(
            $c->get(ContentService::class),
            $c->get(SettingsService::class),
        ));

        $container->set(MailTransport::class, static function (Container $c): MailTransport {
            $config = $c->get(Config::class);

            // Le transport factice n'est activable que par variable
            // d'environnement : il ne peut jamais être choisi depuis l'UI.
            if ($config->string('mail.transport', 'smtp') === 'fake') {
                return new FakeMailTransport($c->get(Paths::class)->storage('temp/mail'));
            }

            $settings = $c->get(SettingsService::class);

            $publicUrl = $settings->string('site.public_url');
            $helo = $publicUrl === '' ? 'localhost' : (string) (parse_url($publicUrl, PHP_URL_HOST) ?? 'localhost');

            $fromAddress = $settings->string('mail.from_address');
            if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
                // `localhost` seul ne forme pas une adresse e-mail valide.
                $candidate = 'noreply@' . $helo;
                $fromAddress = filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false
                    ? $candidate
                    : 'noreply@localhost.localdomain';
            }

            $from = new MailAddress($fromAddress, $settings->string('mail.from_name'));

            return new SmtpMailTransport(
                $settings->string('mail.smtp_host'),
                $settings->int('mail.smtp_port'),
                $settings->string('mail.smtp_username'),
                $settings->isSecretDefined('mail.smtp_password')
                    ? (string) $settings->get('mail.smtp_password')
                    : '',
                $settings->string('mail.smtp_encryption'),
                $from,
                $helo,
            );
        });

        $container->set(MailRepository::class, static fn (Container $c): MailRepository
            => new MailRepository($c->get(Database::class)));

        $container->set(MailService::class, static fn (Container $c): MailService => new MailService(
            $c->get(MailTransport::class),
            $c->get(View::class),
            $c->get(Translator::class),
            $c->get(SettingsService::class),
            $c->get(MailRepository::class),
            $c->get(Logger::class),
            $c->get(ReplyToken::class),
        ));

        // --- Notifications et push ---------------------------------------
        $container->set(VapidKeyManager::class, static fn (Container $c): VapidKeyManager
            => new VapidKeyManager($c->get(SettingsService::class)));

        $container->set(PushSubscriptionRepository::class, static fn (Container $c): PushSubscriptionRepository
            => new PushSubscriptionRepository($c->get(Database::class)));

        $container->set(PushProvider::class, static function (Container $c): PushProvider {
            $config = $c->get(Config::class);
            $keys = $c->get(VapidKeyManager::class);

            // Le fournisseur factice partage la clé publique de l'installation :
            // le parcours d'abonnement du navigateur est réellement exercé.
            if ($config->string('push.provider', 'webpush') === 'fake') {
                return new FakePushProvider(
                    $keys->publicKey(),
                    $c->get(Paths::class)->storage('temp/push')
                );
            }

            return new WebPushProvider($keys->vapid(), $c->get(HttpFetcher::class));
        });

        $container->set(NotificationRepository::class, static fn (Container $c): NotificationRepository
            => new NotificationRepository($c->get(Database::class)));

        $container->set(NotificationPreferenceRepository::class, static fn (Container $c): NotificationPreferenceRepository
            => new NotificationPreferenceRepository($c->get(Database::class)));

        $container->set(NotificationService::class, static fn (Container $c): NotificationService
            => new NotificationService(
                $c->get(MailService::class),
                $c->get(PushProvider::class),
                $c->get(PushSubscriptionRepository::class),
                $c->get(NotificationRepository::class),
                $c->get(NotificationPreferenceRepository::class),
                $c->get(Translator::class),
                $c->get(SettingsService::class),
                $c->get(Logger::class),
            ));

        // --- Application installable --------------------------------------
        $container->set(ManifestBuilder::class, static function (Container $c): ManifestBuilder {
            $context = $c->has(RequestContext::class) ? $c->get(RequestContext::class) : null;

            return new ManifestBuilder(
                $c->get(SettingsService::class),
                $c->get(Translator::class),
                $context instanceof RequestContext ? $context->request->basePath : '',
            );
        });

        $container->set(IconGenerator::class, static fn (Container $c): IconGenerator
            => new IconGenerator($c->get(Paths::class)->storage('cache/icons')));

        $container->set(MailDnsChecker::class, static fn (): MailDnsChecker => new MailDnsChecker());

        // --- Disponibilités et prix ---------------------------------------
        $container->set(RateRepository::class, static fn (Container $c): RateRepository
            => new RateRepository($c->get(Database::class)));

        $container->set(AvailabilityBlockRepository::class, static fn (Container $c): AvailabilityBlockRepository
            => new AvailabilityBlockRepository($c->get(Database::class)));

        $container->set(PriceCalculator::class, static fn (Container $c): PriceCalculator => new PriceCalculator(
            $c->get(SettingsService::class),
            $c->get(RateRepository::class),
            $c->get(Config::class)->string('app.currency', 'EUR'),
        ));

        $container->set(StayRules::class, static fn (Container $c): StayRules => new StayRules(
            $c->get(SettingsService::class),
            self::propertyTimezone($c),
        ));

        $container->set(BookingRepository::class, static fn (Container $c): BookingRepository
            => new BookingRepository($c->get(Database::class)));

        $container->set(BookingEventRepository::class, static fn (Container $c): BookingEventRepository
            => new BookingEventRepository($c->get(Database::class)));

        $container->set(PromoCodeRepository::class, static fn (Container $c): PromoCodeRepository
            => new PromoCodeRepository($c->get(Database::class)));

        $container->set(WaitlistRepository::class, static fn (Container $c): WaitlistRepository
            => new WaitlistRepository($c->get(Database::class)));

        $container->set(AvailabilityService::class, static fn (Container $c): AvailabilityService
            => new AvailabilityService(
                $c->get(AvailabilityBlockRepository::class),
                $c->get(RateRepository::class),
                $c->get(PriceCalculator::class),
                $c->get(StayRules::class),
                self::propertyTimezone($c),
                $c->get(BookingRepository::class),
            ));

        $container->set(BookingService::class, static fn (Container $c): BookingService => new BookingService(
            $c->get(BookingRepository::class),
            $c->get(BookingEventRepository::class),
            $c->get(PromoCodeRepository::class),
            $c->get(WaitlistRepository::class),
            $c->get(StayRules::class),
            $c->get(AvailabilityService::class),
            $c->get(PriceCalculator::class),
            $c->get(SettingsService::class),
            $c->get(Logger::class),
            $c->get(NotificationService::class),
            $c->get(MailService::class),
            $c->get(AuditTrail::class),
        ));

        $container->set(QuoteService::class, static fn (Container $c): QuoteService => new QuoteService(
            $c->get(StayRules::class),
            $c->get(AvailabilityService::class),
            $c->get(PriceCalculator::class),
        ));

        $container->set(PaymentRepository::class, static fn (Container $c): PaymentRepository
            => new PaymentRepository($c->get(Database::class)));

        $container->set(WebhookRepository::class, static fn (Container $c): WebhookRepository
            => new WebhookRepository($c->get(Database::class)));

        $container->set(TouristTaxCalculator::class, static fn (Container $c): TouristTaxCalculator
            => new TouristTaxCalculator($c->get(SettingsService::class)));

        $container->set(PaymentProvider::class, static function (Container $c): PaymentProvider {
            // Le fournisseur factice n'est activable que par variable
            // d'environnement, jamais depuis l'interface : sinon un visiteur
            // pourrait confirmer un séjour sans avoir rien payé.
            if ($c->get(Config::class)->string('payment.provider', '') === FakePaymentProvider::NAME) {
                return new FakePaymentProvider(
                    '/fr/payment/return',
                    $c->get(Paths::class)->storage('temp/payments.json'),
                );
            }

            $settings = $c->get(SettingsService::class);

            if ($settings->string('payment.provider') === MolliePaymentProvider::NAME) {
                $key = $settings->get('payment.mollie_api_key');
                $provider = new MolliePaymentProvider(
                    is_string($key) ? $key : '',
                    $c->get(HttpFetcher::class),
                );

                if ($provider->isConfigured()) {
                    return $provider;
                }
            }

            // Sans clé utilisable : pas de paiement en ligne du tout. Le
            // virement et l'encaissement manuel restent disponibles.
            return new NullPaymentProvider();
        });

        $container->set(PaymentService::class, static fn (Container $c): PaymentService => new PaymentService(
            $c->get(PaymentRepository::class),
            $c->get(WebhookRepository::class),
            $c->get(BookingRepository::class),
            $c->get(BookingEventRepository::class),
            $c->get(BookingService::class),
            $c->get(PaymentProvider::class),
            $c->get(SettingsService::class),
            $c->get(TouristTaxCalculator::class),
            $c->get(Logger::class),
            $c->get(UserRepository::class),
            $c->get(NotificationService::class),
            $c->get(AuditTrail::class),
        ));

        // --- Documents, contrats et courrier entrant ---------------------
        $container->set(DocumentRepository::class, static fn (Container $c): DocumentRepository
            => new DocumentRepository($c->get(Database::class)));

        $container->set(DocumentService::class, static fn (Container $c): DocumentService => new DocumentService(
            $c->get(DocumentRepository::class),
            $c->get(Paths::class),
            $c->get(Logger::class),
            $c->get(AuditTrail::class),
        ));

        $container->set(ContractBuilder::class, static fn (Container $c): ContractBuilder => new ContractBuilder(
            $c->get(Translator::class),
            $c->get(Formatter::class),
            $c->get(SettingsService::class),
        ));

        $container->set(ContractRepository::class, static fn (Container $c): ContractRepository
            => new ContractRepository($c->get(Database::class)));

        $container->set(ContractService::class, static fn (Container $c): ContractService => new ContractService(
            $c->get(ContractBuilder::class),
            $c->get(ContractRepository::class),
            $c->get(DocumentService::class),
            $c->get(DocumentRepository::class),
            $c->get(PaymentRepository::class),
            $c->get(BookingRepository::class),
            $c->get(BookingEventRepository::class),
            $c->get(Logger::class),
            $c->get(AuditTrail::class),
        ));

        $container->set(InboundMailRepository::class, static fn (Container $c): InboundMailRepository
            => new InboundMailRepository($c->get(Database::class)));

        $container->set(MimeParser::class, static fn (): MimeParser => new MimeParser());

        // La boîte factice n'est activable que par variable d'environnement,
        // comme les autres fournisseurs de test.
        $container->set(ImapProvider::class, static function (Container $c): ImapProvider {
            if ($c->get(Config::class)->string('imap.provider', '') === FakeImapProvider::NAME) {
                return new FakeImapProvider($c->get(Paths::class)->storage('temp/imap'));
            }

            $settings = $c->get(SettingsService::class);
            $password = $settings->get('imap.password');

            return new ImapClient(
                $settings->string('imap.host'),
                $settings->int('imap.port'),
                $settings->string('imap.username'),
                is_string($password) ? $password : '',
                $settings->string('imap.mailbox'),
                $settings->string('imap.encryption'),
            );
        });

        $container->set(InboundMailService::class, static fn (Container $c): InboundMailService
            => new InboundMailService(
                $c->get(ImapProvider::class),
                $c->get(InboundMailRepository::class),
                $c->get(BookingRepository::class),
                $c->get(BookingEventRepository::class),
                $c->get(DocumentService::class),
                $c->get(MimeParser::class),
                $c->get(HtmlSanitizer::class),
                $c->get(ReplyToken::class),
                $c->get(SettingsService::class),
                $c->get(Logger::class),
                $c->get(AuditTrail::class),
            ));

        $container->set(TokenRepository::class, static fn (Container $c): TokenRepository
            => new TokenRepository($c->get(Database::class)));

        $container->set(ConsentRepository::class, static fn (Container $c): ConsentRepository
            => new ConsentRepository($c->get(Database::class)));

        $container->set(AccountService::class, static fn (Container $c): AccountService => new AccountService(
            $c->get(UserRepository::class),
            $c->get(TokenRepository::class),
            $c->get(SessionRepository::class),
            $c->get(ConsentRepository::class),
            $c->get(PasswordHasher::class),
            $c->get(MailService::class),
            $c->get(RateLimiter::class),
            $c->get(Logger::class),
            $c->get(AuditTrail::class),
        ));

        $container->set(WebAuthnCredentialRepository::class, static fn (Container $c): WebAuthnCredentialRepository
            => new WebAuthnCredentialRepository($c->get(Database::class)));

        $container->set(WebAuthnService::class, static function (Container $c) : WebAuthnService {
            $settings = $c->get(SettingsService::class);
            $publicUrl = rtrim($settings->string('site.public_url'), '/');

            $context = $c->has(RequestContext::class) ? $c->get(RequestContext::class) : null;
            $origin = $publicUrl;
            $host = $publicUrl === '' ? '' : (string) (parse_url($publicUrl, PHP_URL_HOST) ?? '');

            if ($context instanceof RequestContext) {
                // En l'absence d'URL publique configurée, l'origine effective de
                // la requête fait foi : WebAuthn exige une correspondance exacte.
                $requestOrigin = ($context->request->isSecure() ? 'https://' : 'http://') . $context->request->host();
                if ($origin === '') {
                    $origin = $requestOrigin;
                }
                if ($host === '') {
                    $host = (string) (parse_url($requestOrigin, PHP_URL_HOST) ?? 'localhost');
                }
            }

            return new WebAuthnService(
                $c->get(WebAuthnCredentialRepository::class),
                $c->get(Session::class),
                $host === '' ? 'localhost' : $host,
                $settings->string('property.name') !== '' ? $settings->string('property.name') : 'SecondStay',
                $origin === '' ? 'http://localhost' : $origin,
                $c->get(AuditTrail::class),
            );
        });

        $container->set(DiagnosticRunner::class, static function (Container $c) use ($appVersion): DiagnosticRunner {
            $database = self::optionalDatabase($c);
            $settings = null;
            if ($database !== null) {
                try {
                    $settings = $c->get(SettingsService::class);
                } catch (Throwable) {
                    $settings = null;
                }
            }

            $runner = new DiagnosticRunner(
                $c->get(Paths::class),
                $database,
                $settings,
                $c->get(MaintenanceMode::class),
                $appVersion,
            );

            if ($database !== null && $settings !== null) {
                // La sonde SMTP n'est déclenchée que sur demande explicite :
                // afficher la page ne doit pas ouvrir de connexion sortante.
                $probe = $c->has(RequestContext::class)
                    && $c->get(RequestContext::class)->request->query('probe') === 'smtp';

                $runner->register(new NotificationDiagnostics(
                    $settings,
                    $c->get(MailService::class),
                    $c->get(MailDnsChecker::class),
                    $c->get(PushProvider::class),
                    $c->get(PushSubscriptionRepository::class),
                    $probe,
                ));

                // Comme la sonde SMTP, la connexion IMAP n'est ouverte que
                // sur demande explicite.
                $runner->register(new MailboxDiagnostics(
                    $settings,
                    $c->get(ImapProvider::class),
                    $c->has(RequestContext::class)
                        && $c->get(RequestContext::class)->request->query('probe') === 'imap',
                ));
            }

            return $runner;
        });
    }

    /**
     * Fuseau du logement, avec repli sur celui de l'application.
     */
    private static function propertyTimezone(Container $c): string
    {
        $timezone = $c->get(SettingsService::class)->string('site.timezone');

        return $timezone !== '' ? $timezone : $c->get(Config::class)->string('app.timezone', 'Europe/Paris');
    }

    public static function optionalDatabase(Container $container): ?Database
    {
        try {
            $config = $container->get(Config::class);
            if ($config->string('database.name') === '') {
                return null;
            }
            $database = $container->get(Database::class);

            return $database->isReachable() ? $database : null;
        } catch (Throwable) {
            return null;
        }
    }
}
