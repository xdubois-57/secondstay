<?php

declare(strict_types=1);

namespace SecondStay\Diagnostics;

use SecondStay\Mail\MailService;
use SecondStay\Push\PushProvider;
use SecondStay\Push\PushSubscriptionRepository;
use SecondStay\Settings\SettingsService;
use Throwable;

/**
 * Contrôles e-mail et notifications (SPECIFICATIONS.md §18).
 *
 * Aucun secret n'apparaît : on indique si une configuration est présente et
 * fonctionnelle, jamais sa valeur.
 */
final class NotificationDiagnostics
{
    public const CATEGORY = 'notification';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MailService $mail,
        private readonly MailDnsChecker $dns,
        private readonly PushProvider $push,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly bool $probeSmtp = false,
    ) {
    }

    /**
     * @return list<DiagnosticResult>
     */
    public function __invoke(): array
    {
        return array_merge($this->checkMail(), $this->checkDns(), $this->checkPush());
    }

    /**
     * @return list<DiagnosticResult>
     */
    private function checkMail(): array
    {
        $host = $this->settings->string('mail.smtp_host');
        $from = $this->settings->string('mail.from_address');

        $results = [];

        $results[] = new DiagnosticResult(
            'mail_sender',
            self::CATEGORY,
            filter_var($from, FILTER_VALIDATE_EMAIL) !== false ? DiagnosticStatus::Ok : DiagnosticStatus::Warning,
            filter_var($from, FILTER_VALIDATE_EMAIL) !== false
                ? 'diagnostics.mail.sender_ok'
                : 'diagnostics.mail.sender_missing',
            ['domain' => MailDnsChecker::domainOf($from)],
        );

        if ($host === '') {
            $results[] = new DiagnosticResult(
                'mail_smtp',
                self::CATEGORY,
                DiagnosticStatus::Warning,
                'diagnostics.mail.smtp_missing',
            );

            return $results;
        }

        if (!$this->probeSmtp) {
            // Une connexion sortante ne doit pas être ouverte à chaque
            // affichage de la page : la sonde est déclenchée explicitement.
            $results[] = new DiagnosticResult(
                'mail_smtp',
                self::CATEGORY,
                DiagnosticStatus::Ok,
                'diagnostics.mail.smtp_configured',
                ['port' => $this->settings->int('mail.smtp_port')],
            );

            return $results;
        }

        try {
            $verification = $this->mail->verify();
        } catch (Throwable) {
            $verification = ['ok' => false, 'detail' => 'mail.error.connection_failed'];
        }

        $results[] = new DiagnosticResult(
            'mail_smtp',
            self::CATEGORY,
            $verification['ok'] ? DiagnosticStatus::Ok : DiagnosticStatus::Error,
            $verification['ok'] ? 'diagnostics.mail.smtp_ok' : $verification['detail'],
        );

        return $results;
    }

    /**
     * SPF, DKIM et DMARC du domaine d'expédition. SecondStay ne signe pas
     * lui-même : le rôle du diagnostic est d'alerter le propriétaire.
     *
     * @return list<DiagnosticResult>
     */
    private function checkDns(): array
    {
        $domain = MailDnsChecker::domainOf($this->settings->string('mail.from_address'));
        if ($domain === '') {
            return [];
        }

        $report = $this->dns->check($domain, $this->settings->string('mail.dkim_selector'));

        $results = [];
        foreach (['spf', 'dkim', 'dmarc'] as $record) {
            /** @var array{status: string} $entry */
            $entry = $report[$record];
            $status = match ($entry['status']) {
                'ok' => DiagnosticStatus::Ok,
                'weak', 'missing' => DiagnosticStatus::Warning,
                default => DiagnosticStatus::NotApplicable,
            };

            $results[] = new DiagnosticResult(
                'mail_' . $record,
                self::CATEGORY,
                $status,
                'diagnostics.mail.' . $record . '_' . $entry['status'],
                ['domain' => $domain],
            );
        }

        return $results;
    }

    /**
     * @return list<DiagnosticResult>
     */
    private function checkPush(): array
    {
        if (!$this->settings->bool('notification.push_enabled')) {
            return [new DiagnosticResult(
                'push',
                self::CATEGORY,
                DiagnosticStatus::NotApplicable,
                'diagnostics.push.disabled',
            )];
        }

        if (!$this->push->isConfigured()) {
            return [new DiagnosticResult(
                'push',
                self::CATEGORY,
                DiagnosticStatus::Error,
                'diagnostics.push.keys_missing',
            )];
        }

        return [new DiagnosticResult(
            'push',
            self::CATEGORY,
            DiagnosticStatus::Ok,
            'diagnostics.push.ready',
            ['devices' => $this->countDevices()],
        )];
    }

    private function countDevices(): int
    {
        try {
            return $this->subscriptions->countAll();
        } catch (Throwable) {
            return 0;
        }
    }
}
