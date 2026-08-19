<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;

/**
 * Boîte de réception factice, réservée aux tests automatisés.
 *
 * Cet endpoint n'existe que lorsque le transport e-mail factice est activé par
 * la variable d'environnement `SECONDSTAY_MAIL_TRANSPORT=fake`. En production
 * il renvoie 404 : aucun contenu d'e-mail n'est jamais exposé.
 */
final class DevMailboxController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->assertFakeTransport();

        $directory = $this->paths()->storage('temp/mail');
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

        return Response::json(['messages' => $messages]);
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

    private function assertFakeTransport(): void
    {
        if ($this->config()->string('mail.transport', 'smtp') !== 'fake') {
            throw new NotFoundException('Boîte de test indisponible.');
        }
    }
}
