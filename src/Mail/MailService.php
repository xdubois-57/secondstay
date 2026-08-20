<?php

declare(strict_types=1);

namespace SecondStay\Mail;

use SecondStay\Core\View;
use SecondStay\I18n\Locales;
use SecondStay\I18n\Translator;
use SecondStay\Imap\ReplyToken;
use SecondStay\Logging\Logger;
use SecondStay\Settings\SettingsService;
use Throwable;

/**
 * Composition et envoi des e-mails transactionnels.
 *
 * Le template est rendu dans la langue du destinataire (I18N.md §8) et chaque
 * tentative est journalisée séparément (ARCHITECTURE.md §14).
 */
final class MailService
{
    public function __construct(
        private readonly MailTransport $transport,
        private readonly View $view,
        private readonly Translator $translator,
        private readonly SettingsService $settings,
        private readonly MailRepository $repository,
        private readonly Logger $logger,
        private readonly ?ReplyToken $replyToken = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->transport->isConfigured();
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    public function verify(): array
    {
        return $this->transport->verify();
    }

    /**
     * Expéditeur des messages sortants.
     *
     * Une installation qui n'a pas encore renseigné son adresse d'expédition
     * doit tout de même pouvoir envoyer une confirmation de compte : on
     * dérive alors l'adresse du domaine public du site, puis, à défaut, d'un
     * domaine local valide. La méthode ne lève jamais d'exception.
     */
    public function from(): MailAddress
    {
        $address = $this->settings->string('mail.from_address');
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $address = 'noreply@' . $this->publicHost();
        }

        $name = $this->settings->string('mail.from_name');
        if ($name === '') {
            $name = $this->settings->string('property.name');
        }

        return new MailAddress($address, $name);
    }

    /**
     * Domaine utilisable dans une adresse e-mail, déduit de l'URL publique.
     */
    private function publicHost(): string
    {
        $host = parse_url($this->settings->string('site.public_url'), PHP_URL_HOST);
        if (is_string($host) && filter_var('noreply@' . $host, FILTER_VALIDATE_EMAIL) !== false) {
            return $host;
        }

        // `localhost` seul n'est pas une adresse e-mail valide.
        return 'localhost.localdomain';
    }

    /**
     * Adresse de réponse.
     *
     * Lorsqu'un séjour est connu, l'adresse est étiquetée de sa référence
     * signée : la réponse du client revient alors rattachée au bon séjour,
     * quoi que son logiciel de messagerie fasse des en-têtes de fil
     * (SPECIFICATIONS.md §36).
     */
    public function replyTo(string $bookingReference = ''): ?MailAddress
    {
        $address = $this->settings->string('imap.reply_address');
        if ($address === '') {
            $address = $this->settings->string('mail.reply_to');
        }

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        if ($bookingReference !== '' && $this->replyToken !== null) {
            $tagged = $this->replyToken->address($address, $bookingReference);
            if (filter_var($tagged, FILTER_VALIDATE_EMAIL) !== false) {
                return new MailAddress($tagged);
            }
        }

        return new MailAddress($address);
    }

    /**
     * Envoie un e-mail transactionnel rendu dans la langue du destinataire.
     *
     * @param array<string, mixed> $context
     *
     * @return array{ok: bool, message_id: string, error: string}
     */
    public function send(
        string $template,
        MailAddress $to,
        string $locale,
        array $context = [],
        ?int $userId = null,
        ?string $subjectKey = null,
        ?int $bookingId = null,
        string $bookingReference = '',
    ): array {
        $locale = Locales::isSupported($locale) ? $locale : Locales::FALLBACK;
        $previousLocale = $this->translator->locale();
        $this->translator->setLocale($locale);

        try {
            $subject = $this->translator->trans(
                $subjectKey ?? 'mail.' . $template . '.subject',
                $this->stringParameters($context),
                $locale
            );

            $html = $this->view->render('mail/' . $template . '.html.twig', $context + [
                'locale' => $locale,
                'subject' => $subject,
                'property_name' => $this->settings->string('property.name'),
                'site_url' => rtrim($this->settings->string('site.public_url'), '/'),
            ]);

            $message = new MailMessage(
                $this->from(),
                $to,
                $subject,
                $html,
                '',
                $locale,
                $template,
                $this->replyTo($bookingReference),
            );

            $recordId = $this->repository->record([
                'direction' => 'outbound',
                'status' => 'queued',
                'template' => $template,
                'locale' => $locale,
                'to_address' => $to->address,
                'to_name' => $to->name,
                'subject' => $subject,
                'user_id' => $userId,
                // Rattacher l'envoi au séjour permet à une réponse citant son
                // `Message-ID` d'être rattachée au même séjour, sans jeton.
                'booking_id' => $bookingId,
                'correlation_id' => $this->logger->correlationId(),
            ]);

            try {
                $messageId = $this->transport->send($message);
                $this->repository->markSent($recordId, $messageId);
                $this->logger->info('mail', 'E-mail envoyé', [
                    'template' => $template,
                    'locale' => $locale,
                    'to' => $this->maskAddress($to->address),
                ]);

                return ['ok' => true, 'message_id' => $messageId, 'error' => ''];
            } catch (Throwable $throwable) {
                $this->repository->markFailed($recordId, $throwable->getMessage());
                $this->logger->error('mail', 'Envoi d’e-mail impossible', [
                    'template' => $template,
                    'reason' => $throwable->getMessage(),
                    'to' => $this->maskAddress($to->address),
                ]);

                return ['ok' => false, 'message_id' => '', 'error' => $throwable->getMessage()];
            }
        } finally {
            $this->translator->setLocale($previousLocale);
        }
    }

    /**
     * Une adresse n'apparaît jamais en clair dans les journaux (SECURITY.md §17).
     */
    private function maskAddress(string $address): string
    {
        $parts = explode('@', $address);
        if (count($parts) !== 2) {
            return '***';
        }

        $local = $parts[0];
        $visible = mb_substr($local, 0, 1);

        return $visible . str_repeat('*', max(1, mb_strlen($local) - 1)) . '@' . $parts[1];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, string|int|float>
     */
    private function stringParameters(array $context): array
    {
        $parameters = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }
}
