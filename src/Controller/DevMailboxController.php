<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;

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
