<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Booking\BookingRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Document\DocumentRepository;
use SecondStay\Imap\ImapProvider;
use SecondStay\Imap\InboundMailRepository;
use SecondStay\Imap\InboundMailService;
use SecondStay\Settings\SettingsService;

/**
 * Courrier entrant : relève, rattachement et timeline de communication
 * (SPECIFICATIONS.md §36 et §37).
 */
final class AdminMailboxController extends AdminController
{
    protected function section(): string
    {
        return 'mailbox';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $mails = $this->container->get(InboundMailRepository::class);
        $provider = $this->container->get(ImapProvider::class);

        return $this->renderAdmin('admin/mailbox.html.twig', [
            'meta_title' => $this->trans('mailbox.title'),
            'messages' => $mails->recentInbound(50),
            'unlinked' => $mails->unlinked(50),
            'enabled' => $this->settings()->bool('imap.enabled'),
            'configured' => $provider->isConfigured(),
        ]);
    }

    /**
     * Relève la boîte à la demande.
     *
     * @param array<string, string> $params
     */
    public function synchronise(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $settings = $this->container->get(SettingsService::class);
        if (!$settings->bool('imap.enabled')) {
            $this->flashWarning('mailbox.sync.disabled');

            return $this->redirectToRoute($context, 'admin.mailbox');
        }

        $result = $this->container->get(InboundMailService::class)
            ->synchronise(max(1, $settings->int('imap.batch_size')));

        if ($result['ok'] === false) {
            $this->flashError($result['error']);
        } elseif ($result['imported'] === 0) {
            $this->flashWarning('mailbox.sync.nothing');
        } else {
            $this->flashSuccess('mailbox.sync.done');
        }

        return $this->redirectToRoute($context, 'admin.mailbox');
    }

    /**
     * Rattache manuellement un message à un séjour.
     *
     * @param array<string, string> $params
     */
    public function link(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $booking = $this->container->get(BookingRepository::class)
            ->findByReference((string) $context->request->input('reference', ''));

        if ($booking === null) {
            $this->flashError('mailbox.error.booking_not_found');

            return $this->redirectToRoute($context, 'admin.mailbox');
        }

        $result = $this->container->get(InboundMailService::class)->linkManually(
            (int) ($params['id'] ?? 0),
            $booking,
            $user->id,
            $user->email,
        );

        $result['ok'] ? $this->flashSuccess('mailbox.linked') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.mailbox');
    }

    /**
     * Détail d'un message reçu, avec ses pièces jointes.
     *
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $mail = $this->container->get(InboundMailRepository::class)->find((int) ($params['id'] ?? 0));
        if ($mail === null) {
            throw new NotFoundException('Message introuvable.');
        }

        $booking = $mail['booking_id'] === null
            ? null
            : $this->container->get(BookingRepository::class)->find((int) $mail['booking_id']);

        return $this->renderAdmin('admin/mailbox-detail.html.twig', [
            'meta_title' => (string) ($mail['subject'] ?? ''),
            'mail' => $mail,
            'booking' => $booking,
            'documents' => $this->container->get(DocumentRepository::class)->forMail((int) $mail['id']),
        ]);
    }
}
