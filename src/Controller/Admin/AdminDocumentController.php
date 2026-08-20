<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Booking\BookingRepository;
use SecondStay\Contract\ContractService;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentRepository;
use SecondStay\Document\DocumentService;
use SecondStay\Document\DocumentSource;

/**
 * Documents et contrats, côté administration (SPECIFICATIONS.md §41).
 */
final class AdminDocumentController extends AdminController
{
    protected function section(): string
    {
        return 'documents';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        return $this->renderAdmin('admin/documents.html.twig', [
            'meta_title' => $this->trans('document.title'),
            'documents' => $this->container->get(DocumentRepository::class)->recent(100),
            'kinds' => DocumentKind::cases(),
        ]);
    }

    /**
     * Dépôt d'un document sur un séjour.
     *
     * @param array<string, string> $params
     */
    public function upload(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $booking = $this->container->get(BookingRepository::class)->find((int) ($params['id'] ?? 0));
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        /** @var array<string, mixed> $file */
        $file = $context->request->files['document'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flashError('document.error.upload_failed');

            return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
        }

        $contents = file_get_contents((string) $file['tmp_name']);
        if ($contents === false) {
            $this->flashError('document.error.upload_failed');

            return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
        }

        $result = $this->container->get(DocumentService::class)->store(
            $contents,
            (string) ($file['name'] ?? 'document'),
            DocumentKind::fromString((string) $context->request->input('kind', 'other')),
            DocumentSource::Upload,
            $booking->id,
            null,
            $user->id,
            '',
            $booking->locale,
        );

        $result['ok'] ? $this->flashSuccess('document.uploaded') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
    }

    /**
     * @param array<string, string> $params
     */
    public function reclassify(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $documents = $this->container->get(DocumentRepository::class);
        $document = $documents->find((int) ($params['id'] ?? 0));
        if ($document === null) {
            throw new NotFoundException('Document introuvable.');
        }

        $documents->reclassify(
            $document->id,
            DocumentKind::fromString((string) $context->request->input('kind', 'other'))
        );

        $this->flashSuccess('document.reclassified');

        return $this->backToDocument($context, $document->bookingId);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $document = $this->container->get(DocumentRepository::class)->find((int) ($params['id'] ?? 0));
        if ($document === null) {
            throw new NotFoundException('Document introuvable.');
        }

        $this->container->get(DocumentService::class)->delete($document, $user->id);
        $this->flashSuccess('document.deleted');

        return $this->backToDocument($context, $document->bookingId);
    }

    /**
     * Produit un nouvel instantané de contrat.
     *
     * @param array<string, string> $params
     */
    public function generateContract(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $booking = $this->container->get(BookingRepository::class)->find((int) ($params['id'] ?? 0));
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        $document = $this->container->get(ContractService::class)->generate($booking);

        $document === null
            ? $this->flashError('contract.error.unavailable')
            : $this->flashSuccess('document.uploaded');

        return $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $booking->id]);
    }

    private function backToDocument(RequestContext $context, ?int $bookingId): Response
    {
        return $bookingId === null
            ? $this->redirectToRoute($context, 'admin.documents')
            : $this->redirectToRoute($context, 'admin.bookings.show', ['id' => $bookingId]);
    }
}
