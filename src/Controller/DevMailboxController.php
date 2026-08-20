<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Imap\FakeImapProvider;
use SecondStay\Imap\ImapProvider;
use SecondStay\Payment\FakePaymentProvider;
use SecondStay\Payment\PaymentProvider;
use SecondStay\Payment\PaymentStatus;

/**
 * Boîtes factices d'e-mail et de notification, réservées aux tests
 * automatisés.
 *
 * Ces endpoints n'existent que lorsque le transport factice correspondant est
 * activé par variable d'environnement (`SECONDSTAY_MAIL_TRANSPORT=fake`,
 * `SECONDSTAY_PUSH_PROVIDER=fake`). En production ils renvoient 404 : aucun
 * contenu de message n'est jamais exposé.
 */
final class DevMailboxController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->assertFakeTransport();

        return Response::json(['messages' => $this->readSpool($this->paths()->storage('temp/mail'))]);
    }

    /**
     * @param array<string, string> $params
     */
    public function purge(RequestContext $context, array $params = []): Response
    {
        $this->assertFakeTransport();

        foreach (glob($this->paths()->storage('temp/mail') . '/*.json') ?: [] as $file) {
            unlink($file);
        }

        return Response::json(['ok' => true]);
    }

    /**
     * Notifications réellement poussées vers le fournisseur factice.
     *
     * @param array<string, string> $params
     */
    public function notifications(RequestContext $context, array $params = []): Response
    {
        if ($this->config()->string('push.provider', 'webpush') !== 'fake') {
            throw new NotFoundException('Boîte de test indisponible.');
        }

        return Response::json(['messages' => $this->readSpool($this->paths()->storage('temp/push'))]);
    }

    /**
     * État des paiements chez le fournisseur factice.
     *
     * Le scénario complet — acompte, notification, confirmation — n'est
     * jouable en bout en bout que si le test peut faire évoluer l'état
     * « côté fournisseur », comme le ferait un vrai encaissement. Rien n'est
     * écrit dans l'application ici : seul le fournisseur factice change
     * d'avis, et le webhook reste le seul chemin qui met à jour SecondStay.
     *
     * @param array<string, string> $params
     */
    public function payments(RequestContext $context, array $params = []): Response
    {
        $provider = $this->fakePaymentProvider();

        $references = [];
        foreach ($provider->references() as $reference) {
            $state = $provider->fetch($reference);
            $references[] = [
                'reference' => $reference,
                'status' => $state['status']->value,
                'amount_cents' => $state['amount_cents'],
            ];
        }

        return Response::json(['payments' => $references]);
    }

    /**
     * @param array<string, string> $params
     */
    public function settlePayment(RequestContext $context, array $params = []): Response
    {
        $provider = $this->fakePaymentProvider();

        $reference = (string) $context->request->input('reference', '');
        $status = PaymentStatus::fromString((string) $context->request->input('status', 'paid'));

        return Response::json(['ok' => $provider->settle($reference, $status)]);
    }

    /**
     * Dépose un message dans la boîte de réception factice.
     *
     * C'est l'équivalent, pour l'IMAP, de la boîte d'envoi factice : le
     * scénario « réponse par e-mail avec contrat signé » devient jouable de
     * bout en bout sans serveur de messagerie.
     *
     * @param array<string, string> $params
     */
    public function deliver(RequestContext $context, array $params = []): Response
    {
        $provider = $this->fakeImapProvider();

        $raw = $context->request->body;
        if ($raw === '') {
            $raw = (string) $context->request->input('raw', '');
        }

        if (trim($raw) === '') {
            return Response::json(['ok' => false, 'error' => 'empty'], 400);
        }

        return Response::json(['ok' => true, 'uid' => $provider->deliver($raw)]);
    }

    private function fakeImapProvider(): FakeImapProvider
    {
        $provider = $this->container->get(ImapProvider::class);

        if (!$provider instanceof FakeImapProvider) {
            throw new NotFoundException('Boîte de test indisponible.');
        }

        return $provider;
    }

    private function fakePaymentProvider(): FakePaymentProvider
    {
        $provider = $this->container->get(PaymentProvider::class);

        if (!$provider instanceof FakePaymentProvider) {
            throw new NotFoundException('Fournisseur de test indisponible.');
        }

        return $provider;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readSpool(string $directory): array
    {
        $files = glob($directory . '/*.json');
        if ($files === false) {
            $files = [];
        }

        rsort($files);
        $messages = [];

        foreach (array_slice($files, 0, 50) as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    private function assertFakeTransport(): void
    {
        if ($this->config()->string('mail.transport', 'smtp') !== 'fake') {
            throw new NotFoundException('Boîte de test indisponible.');
        }
    }
}
