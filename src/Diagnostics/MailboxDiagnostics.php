<?php

declare(strict_types=1);

namespace SecondStay\Diagnostics;

use SecondStay\Imap\ImapProvider;
use SecondStay\Settings\SettingsService;

/**
 * Contrôles de la boîte de réception (SPECIFICATIONS.md §24).
 *
 * Comme pour SMTP, la connexion réelle n'est ouverte que sur demande
 * explicite : afficher la page de diagnostics ne doit jamais provoquer de
 * trafic sortant.
 */
final class MailboxDiagnostics
{
    public const CATEGORY = 'mailbox';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ImapProvider $provider,
        private readonly bool $probe = false,
    ) {
    }

    /**
     * @return list<DiagnosticResult>
     */
    public function __invoke(): array
    {
        $enabled = $this->settings->bool('imap.enabled');

        if (!$enabled) {
            return [
                new DiagnosticResult(
                    'imap_enabled',
                    self::CATEGORY,
                    DiagnosticStatus::NotApplicable,
                    'diagnostics.mailbox.disabled',
                ),
            ];
        }

        $results = [
            new DiagnosticResult(
                'imap_configured',
                self::CATEGORY,
                $this->provider->isConfigured() ? DiagnosticStatus::Ok : DiagnosticStatus::Error,
                $this->provider->isConfigured()
                    ? 'diagnostics.mailbox.configured'
                    : 'diagnostics.mailbox.not_configured',
                ['mailbox' => $this->settings->string('imap.mailbox')],
            ),
        ];

        $results[] = $this->checkReplyAddress();

        if ($this->probe && $this->provider->isConfigured()) {
            $verification = $this->provider->verify();

            $results[] = new DiagnosticResult(
                'imap_connection',
                self::CATEGORY,
                $verification['ok'] ? DiagnosticStatus::Ok : DiagnosticStatus::Error,
                $verification['ok'] ? 'diagnostics.mailbox.reachable' : $verification['detail'],
            );
        }

        return $results;
    }

    /**
     * L'adresse de réponse doit accepter le sous-adressage : sans lui, le
     * rattachement automatique des réponses repose uniquement sur les
     * en-têtes de fil, que certains logiciels de messagerie perdent.
     */
    private function checkReplyAddress(): DiagnosticResult
    {
        $address = $this->settings->string('imap.reply_address');

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return new DiagnosticResult(
                'imap_reply_address',
                self::CATEGORY,
                DiagnosticStatus::Warning,
                'diagnostics.mailbox.reply_missing',
            );
        }

        return new DiagnosticResult(
            'imap_reply_address',
            self::CATEGORY,
            DiagnosticStatus::Ok,
            'diagnostics.mailbox.reply_ok',
            ['address' => $address],
        );
    }
}
