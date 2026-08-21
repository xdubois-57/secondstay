<?php

declare(strict_types=1);

namespace SecondStay\Settings;

use RuntimeException;
use SecondStay\I18n\Locales;

/**
 * Registre des réglages typés de l'installation.
 *
 * Ne pas rendre chaque constante configurable (ARCHITECTURE.md §7) : seules
 * les vraies différences de déploiement figurent ici.
 */
final class SettingRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        foreach (self::defaultDefinitions() as $definition) {
            $this->add($definition);
        }
    }

    public function add(SettingDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): SettingDefinition
    {
        if (!isset($this->definitions[$key])) {
            throw new RuntimeException('Réglage inconnu : ' . $key);
        }

        return $this->definitions[$key];
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function forModule(string $module): array
    {
        return array_filter(
            $this->definitions,
            static fn (SettingDefinition $d): bool => $d->module === $module
        );
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        $modules = [];
        foreach ($this->definitions as $definition) {
            if (!in_array($definition->module, $modules, true)) {
                $modules[] = $definition->module;
            }
        }

        return $modules;
    }

    /**
     * @return list<SettingDefinition>
     */
    private static function defaultDefinitions(): array
    {
        return [
            // --- Logement -------------------------------------------------
            new SettingDefinition('property.name', SettingType::String, '', 'property', required: true, max: 190),
            new SettingDefinition('property.address_line1', SettingType::String, '', 'property', max: 190),
            new SettingDefinition('property.address_line2', SettingType::String, '', 'property', max: 190),
            new SettingDefinition('property.postal_code', SettingType::String, '', 'property', max: 12),
            new SettingDefinition('property.city', SettingType::String, '', 'property', max: 120),
            new SettingDefinition('property.country', SettingType::Enum, 'FR', 'property', enumValues: ['FR']),
            new SettingDefinition('property.latitude', SettingType::Decimal, null, 'property', min: -90, max: 90),
            new SettingDefinition('property.longitude', SettingType::Decimal, null, 'property', min: -180, max: 180),
            new SettingDefinition('property.contact_email', SettingType::Email, '', 'property'),
            new SettingDefinition('property.contact_phone', SettingType::String, '', 'property', max: 40),
            new SettingDefinition('property.siret', SettingType::String, '', 'property', max: 20),

            // --- Site -----------------------------------------------------
            new SettingDefinition(
                'site.default_locale',
                SettingType::Enum,
                Locales::FALLBACK,
                'site',
                enumValues: Locales::ALL,
                required: true
            ),
            new SettingDefinition('site.timezone', SettingType::String, 'Europe/Paris', 'site', required: true),
            new SettingDefinition('site.public_url', SettingType::Url, '', 'site'),
            new SettingDefinition('site.season', SettingType::Enum, 'auto', 'site', enumValues: ['auto', 'summer', 'winter']),

            // --- Réservation ----------------------------------------------
            new SettingDefinition('booking.min_nights', SettingType::Integer, 2, 'booking', min: 1, max: 90),
            new SettingDefinition('booking.max_guests', SettingType::Integer, 6, 'booking', min: 1, max: 40),
            new SettingDefinition('booking.checkin_time', SettingType::Time, '16:00', 'booking'),
            new SettingDefinition('booking.checkout_time', SettingType::Time, '10:00', 'booking'),
            new SettingDefinition('booking.saturday_to_saturday', SettingType::Bool, false, 'booking'),
            new SettingDefinition('booking.hold_minutes', SettingType::Duration, 30, 'booking', min: 5, max: 1440),
            new SettingDefinition('booking.min_adults', SettingType::Integer, 1, 'booking', min: 1, max: 40),
            new SettingDefinition('booking.max_children', SettingType::Integer, 4, 'booking', min: 0, max: 40),
            new SettingDefinition('booking.max_infants', SettingType::Integer, 2, 'booking', min: 0, max: 40),
            // 0 = pas de contrainte de multiple ; 7 = séjours en semaines entières.
            new SettingDefinition('booking.night_multiple', SettingType::Integer, 0, 'booking', min: 0, max: 30),
            new SettingDefinition('booking.max_nights', SettingType::Integer, 60, 'booking', min: 1, max: 365),
            new SettingDefinition(
                'booking.arrival_weekday',
                SettingType::Enum,
                'any',
                'booking',
                enumValues: ['any', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
            ),
            new SettingDefinition('booking.advance_days', SettingType::Integer, 0, 'booking', min: 0, max: 365),
            new SettingDefinition('booking.horizon_days', SettingType::Integer, 540, 'booking', min: 30, max: 1095),
            // Par défaut le propriétaire valide chaque demande : une
            // confirmation automatique se choisit explicitement.
            new SettingDefinition('booking.requires_approval', SettingType::Bool, true, 'booking'),
            new SettingDefinition('booking.allow_waitlist', SettingType::Bool, true, 'booking'),

            // --- Tarifs ---------------------------------------------------
            new SettingDefinition('pricing.default_night_price', SettingType::Money, 12000, 'pricing', min: 0),
            new SettingDefinition(
                'pricing.cleaning_mode',
                SettingType::Enum,
                'mandatory',
                'pricing',
                enumValues: ['none', 'optional', 'mandatory']
            ),
            new SettingDefinition('pricing.cleaning_price', SettingType::Money, 10000, 'pricing', min: 0),
            new SettingDefinition('pricing.deposit_percent', SettingType::Integer, 30, 'pricing', min: 0, max: 100),
            new SettingDefinition('pricing.security_deposit', SettingType::Money, 50000, 'pricing', min: 0),

            // --- Exploitation -------------------------------------------------
            // Responsable appliqué à un séjour qui n'en a pas reçu un
            // explicitement (SPECIFICATIONS.md §48).
            new SettingDefinition('operations.default_manager', SettingType::Integer, 0, 'operations', min: 0),
            new SettingDefinition('operations.prepare_days', SettingType::Integer, 14, 'operations', min: 1, max: 120),
            new SettingDefinition('operations.calendar_enabled', SettingType::Bool, true, 'operations'),
            // Délai laissé au voyageur pour signaler une non-conformité après
            // son arrivée (SPECIFICATIONS.md §53).
            new SettingDefinition(
                'inspection.report_window_hours',
                SettingType::Integer,
                24,
                'operations',
                min: 1,
                max: 168
            ),
            // Le voyageur remplit-il lui-même l'état des lieux depuis « Mon
            // séjour » ? Certains propriétaires préfèrent le faire seuls.
            new SettingDefinition('inspection.guest_enabled', SettingType::Bool, true, 'operations'),

            // --- Contenu local généré (SPECIFICATIONS.md §56 et §57) ---------
            new SettingDefinition('llm.enabled', SettingType::Bool, false, 'llm'),
            new SettingDefinition(
                'llm.provider',
                SettingType::Enum,
                'none',
                'llm',
                enumValues: ['none', 'anthropic']
            ),
            new SettingDefinition('llm.api_key', SettingType::Secret, null, 'llm'),
            new SettingDefinition('llm.model', SettingType::String, 'claude-opus-5', 'llm', max: 64),
            // Consigne libre du propriétaire : le système y ajoute la
            // localisation, la saison, les dates, les sources et le schéma.
            new SettingDefinition('llm.prompt', SettingType::Text, '', 'llm', max: 4000),
            new SettingDefinition('llm.window_weeks', SettingType::Integer, 5, 'llm', min: 1, max: 26),
            new SettingDefinition('llm.refresh_days', SettingType::Integer, 7, 'llm', min: 1, max: 90),

            // --- Mentions légales -------------------------------------------
            // La version des conditions est figée dans chaque contrat : elle
            // doit donc être une valeur explicite, pas une date implicite.
            new SettingDefinition('legal.terms_version', SettingType::String, '', 'legal', max: 24),
            new SettingDefinition('legal.mediator_name', SettingType::String, '', 'legal', max: 190),
            new SettingDefinition('legal.mediator_url', SettingType::Url, '', 'legal'),
            // Fiche de police : rien n'est collecté tant qu'elle n'est pas
            // activée (SPECIFICATIONS.md §64).
            new SettingDefinition('compliance.police_record_enabled', SettingType::Bool, false, 'legal'),
            new SettingDefinition(
                'compliance.police_retention_days',
                SettingType::Integer,
                183,
                'legal',
                min: 1,
                max: 3650
            ),

            // --- Paiements --------------------------------------------------
            new SettingDefinition(
                'payment.provider',
                SettingType::Enum,
                'none',
                'payment',
                enumValues: ['none', 'mollie']
            ),
            new SettingDefinition('payment.mollie_api_key', SettingType::Secret, null, 'payment'),
            new SettingDefinition('payment.balance_days_before', SettingType::Integer, 30, 'payment', min: 0, max: 365),
            new SettingDefinition('payment.transfer_enabled', SettingType::Bool, true, 'payment'),
            new SettingDefinition('payment.beneficiary_name', SettingType::String, '', 'payment', max: 70),
            new SettingDefinition('payment.iban', SettingType::String, '', 'payment', max: 34),
            new SettingDefinition('payment.bic', SettingType::String, '', 'payment', max: 11),
            new SettingDefinition('payment.currency', SettingType::String, 'EUR', 'payment', max: 3),

            // --- Taxe de séjour ---------------------------------------------
            new SettingDefinition('tax.tourist_enabled', SettingType::Bool, false, 'tax'),
            new SettingDefinition('tax.tourist_per_adult_night', SettingType::Money, 0, 'tax', min: 0),
            new SettingDefinition('tax.tourist_cap_per_stay', SettingType::Money, 0, 'tax', min: 0),
            // Territoire et classement : ils déterminent quel barème daté
            // s'applique (SPECIFICATIONS.md §63).
            new SettingDefinition('tax.territory', SettingType::String, '', 'tax', max: 120),
            new SettingDefinition(
                'tax.classification',
                SettingType::Enum,
                'unclassified',
                'tax',
                enumValues: ['unclassified', 'star_1', 'star_2', 'star_3', 'star_4', 'star_5']
            ),

            // --- E-mail -----------------------------------------------------
            new SettingDefinition('mail.from_address', SettingType::Email, '', 'mail'),
            new SettingDefinition('mail.from_name', SettingType::String, '', 'mail', max: 120),
            new SettingDefinition('mail.reply_to', SettingType::Email, '', 'mail'),
            new SettingDefinition('mail.smtp_host', SettingType::String, '', 'mail', max: 190),
            new SettingDefinition('mail.smtp_port', SettingType::Integer, 587, 'mail', min: 1, max: 65535),
            new SettingDefinition(
                'mail.smtp_encryption',
                SettingType::Enum,
                'starttls',
                'mail',
                enumValues: ['none', 'starttls', 'tls']
            ),
            new SettingDefinition('mail.smtp_username', SettingType::String, '', 'mail', max: 190),
            new SettingDefinition('mail.smtp_password', SettingType::Secret, null, 'mail'),
            new SettingDefinition('mail.dkim_selector', SettingType::String, '', 'mail', max: 64),

            // --- Courrier entrant (IMAP) ------------------------------------
            new SettingDefinition('imap.enabled', SettingType::Bool, false, 'imap'),
            new SettingDefinition('imap.host', SettingType::String, '', 'imap', max: 190),
            new SettingDefinition('imap.port', SettingType::Integer, 993, 'imap', min: 1, max: 65535),
            new SettingDefinition(
                'imap.encryption',
                SettingType::Enum,
                'tls',
                'imap',
                enumValues: ['none', 'starttls', 'tls']
            ),
            new SettingDefinition('imap.username', SettingType::String, '', 'imap', max: 190),
            new SettingDefinition('imap.password', SettingType::Secret, null, 'imap'),
            new SettingDefinition('imap.mailbox', SettingType::String, 'INBOX', 'imap', max: 64),
            // Adresse annoncée en `Reply-To`, étiquetée par séjour.
            new SettingDefinition('imap.reply_address', SettingType::Email, '', 'imap'),
            // Renseignés par la synchronisation elle-même, pas par l'humain.
            new SettingDefinition('imap.uid_validity', SettingType::Integer, 0, 'imap', min: 0),
            new SettingDefinition('imap.batch_size', SettingType::Integer, 25, 'imap', min: 1, max: 200),

            // --- Notifications ----------------------------------------------
            new SettingDefinition('notification.push_enabled', SettingType::Bool, false, 'notification'),
            new SettingDefinition('notification.retention_days', SettingType::Integer, 180, 'notification', min: 7, max: 3650),
            new SettingDefinition('push.subject', SettingType::String, '', 'notification', max: 190),
            // Générées par l'installation, jamais versionnées.
            new SettingDefinition('push.vapid_public', SettingType::String, '', 'notification', max: 255),
            new SettingDefinition('push.vapid_private', SettingType::Secret, null, 'notification'),

            // --- Comptes ----------------------------------------------------
            new SettingDefinition('account.allow_signup', SettingType::Bool, true, 'account'),
            new SettingDefinition('account.allow_passkeys', SettingType::Bool, true, 'account'),
            new SettingDefinition('account.require_email_confirmation', SettingType::Bool, true, 'account'),

            // --- Maintenance / sauvegarde ---------------------------------
            new SettingDefinition('maintenance.enabled', SettingType::Bool, false, 'maintenance'),
            new SettingDefinition('maintenance.message', SettingType::Text, '', 'maintenance'),
            new SettingDefinition('backup.retention_count', SettingType::Integer, 7, 'backup', min: 1, max: 100),
            new SettingDefinition('backup.include_media', SettingType::Bool, true, 'backup'),

            // --- Mise à jour ----------------------------------------------
            new SettingDefinition('update.channel', SettingType::Enum, 'stable', 'update', enumValues: ['stable', 'prerelease']),
            new SettingDefinition('update.auto_install', SettingType::Bool, false, 'update'),
            new SettingDefinition(
                'update.repository',
                SettingType::String,
                'xdubois-57/secondstay',
                'update',
                max: 190
            ),

            // --- Journalisation -------------------------------------------
            new SettingDefinition(
                'logging.level',
                SettingType::Enum,
                'info',
                'logging',
                enumValues: ['debug', 'info', 'warning', 'error', 'critical']
            ),
            new SettingDefinition('logging.retention_days', SettingType::Integer, 90, 'logging', min: 1, max: 3650),
        ];
    }
}
