<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Auth\Role;
use SecondStay\Booking\BookingRepository;
use SecondStay\Contract\ContractService;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Document\Document;
use SecondStay\Document\DocumentRepository;
use SecondStay\Document\DocumentService;

/**
 * Consultation des documents et acceptation du contrat, côté voyageur
 * (SPECIFICATIONS.md §39 à §41).
 *
 * Aucun document n'est servi par le serveur web : chaque octet passe par ici,
 * après vérification que le demandeur est bien le titulaire du séjour.
 */
final class DocumentController extends AbstractController
{
    /**
     * Téléchargement d'un document.
     *
     * @param array<string, string> $params
     */
    public function download(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);
        $document = $this->container->get(DocumentRepository::class)->find((int) ($params['id'] ?? 0));

        if ($document === null) {
            throw new NotFoundException('Document introuvable.');
        }

        if (!$this->mayRead($document, $user->id, $user->isOperational())) {
            // Un identifiant de document n'est pas un secret : l'appartenance
            // est vérifiée à chaque accès, et une absence de droit se présente
            // comme une absence de document.
            throw new NotFoundException('Document introuvable.');
        }

        $contents = $this->container->get(DocumentService::class)->read($document);
        if ($contents === null) {
            throw new NotFoundException('Document introuvable.');
        }

        return $this->fileResponse($document, $contents);
    }

    /**
     * Contrat du séjour, généré au besoin.
     *
     * @param array<string, string> $params
     */
    public function contract(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $booking = $this->container->get(BookingRepository::class)
            ->findByReference((string) ($params['reference'] ?? ''));

        if ($booking === null || ($booking->userId !== $user->id && !$user->isOperational())) {
            throw new NotFoundException('Réservation introuvable.');
        }

        $contracts = $this->container->get(ContractService::class);
        $document = $contracts->contractFor($booking);

        if ($document === null) {
            throw new NotFoundException('Contrat indisponible.');
        }

        $contents = $this->container->get(DocumentService::class)->read($document);
        if ($contents === null) {
            throw new NotFoundException('Contrat indisponible.');
        }

        return $this->fileResponse($document, $contents, true);
    }

    /**
     * Acceptation du contrat (SPECIFICATIONS.md §40).
     *
     * @param array<string, string> $params
     */
    public function acceptContract(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $booking = $this->container->get(BookingRepository::class)
            ->findByReference((string) ($params['reference'] ?? ''));

        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        if ($context->request->input('accept') === null) {
            $this->flashError('contract.error.not_accepted');

            return $this->redirectToRoute($context, 'booking.show', ['reference' => $booking->reference]);
        }

        $result = $this->container->get(ContractService::class)->accept(
            $booking,
            $user,
            $context->request->ip(),
            (string) ($context->request->header('User-Agent') ?? ''),
        );

        $result['ok'] ? $this->flashSuccess('contract.accept.success') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'booking.show', ['reference' => $booking->reference]);
    }

    // --- Internes ----------------------------------------------------------

    private function mayRead(Document $document, int $userId, bool $operational): bool
    {
        if ($operational) {
            return true;
        }

        if ($document->bookingId === null || !$document->kind->visibleToCustomer()) {
            return false;
        }

        $booking = $this->container->get(BookingRepository::class)->find($document->bookingId);

        return $booking !== null && $booking->userId === $userId;
    }

    private function fileResponse(Document $document, string $contents, bool $inline = false): Response
    {
        return new Response($contents, 200, [
            'Content-Type' => $document->mime,
            'Content-Length' => (string) strlen($contents),
            // Le nom est entre guillemets et sans caractère de contrôle : il
            // vient d'un e-mail, donc de l'extérieur.
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $inline ? 'inline' : 'attachment',
                str_replace(['"', "\r", "\n"], '', $document->filename)
            ),
            // Un document de séjour ne doit jamais finir dans un cache
            // partagé ni dans l'historique d'un navigateur public.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
