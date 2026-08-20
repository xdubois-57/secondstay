<?php

declare(strict_types=1);

namespace SecondStay\Contract;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Booking\Booking;
use SecondStay\Booking\BookingEventRepository;
use SecondStay\Booking\BookingRepository;
use SecondStay\Booking\SubStatus;
use SecondStay\Document\Document;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentRepository;
use SecondStay\Document\DocumentService;
use SecondStay\Document\DocumentSource;
use SecondStay\Logging\Logger;
use SecondStay\Payment\PaymentRepository;

/**
 * Génération, instantané et acceptation du contrat.
 *
 * Le contrat est un **instantané** : une fois généré il n'est plus régénéré,
 * même si les tarifs, les textes ou le modèle changent. C'est la condition
 * pour qu'un séjour conserve durablement ce que le client a accepté
 * (SPECIFICATIONS.md §39 et §40).
 */
final class ContractService
{
    public function __construct(
        private readonly ContractBuilder $builder,
        private readonly ContractRepository $acceptances,
        private readonly DocumentService $documents,
        private readonly DocumentRepository $documentRepository,
        private readonly PaymentRepository $payments,
        private readonly BookingRepository $bookings,
        private readonly BookingEventRepository $events,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * Contrat du séjour, généré au premier appel puis réutilisé tel quel.
     */
    public function contractFor(Booking $booking): ?Document
    {
        $existing = $this->documentRepository->latestKind($booking->id, DocumentKind::Contract);
        if ($existing !== null) {
            return $existing;
        }

        return $this->generate($booking);
    }

    /**
     * Produit un nouvel instantané de contrat.
     */
    public function generate(Booking $booking): ?Document
    {
        $pdf = $this->builder->build($booking, $this->payments->forBooking($booking->id));

        $result = $this->documents->store(
            $pdf,
            sprintf('contrat-%s.pdf', $booking->reference),
            DocumentKind::Contract,
            DocumentSource::Generated,
            $booking->id,
            null,
            null,
            '',
            $booking->locale,
            ContractBuilder::VERSION,
        );

        if ($result['ok'] === false || $result['document'] === null) {
            $this->logger->error('contract', 'Contrat non enregistré', [
                'booking' => $booking->id,
                'error' => $result['error'],
            ]);

            return null;
        }

        $this->events->record($booking->id, 'contract_generated', [
            'version' => ContractBuilder::VERSION,
            'locale' => $booking->locale,
        ]);

        return $result['document'];
    }

    public function acceptanceFor(Booking $booking): ?ContractAcceptance
    {
        return $this->acceptances->forBooking($booking->id);
    }

    /**
     * Enregistre l'acceptation du contrat par le client.
     *
     * @return array{ok: bool, error: string}
     */
    public function accept(Booking $booking, User $user, string $ipAddress, string $userAgent): array
    {
        if ($booking->userId !== $user->id) {
            return ['ok' => false, 'error' => 'contract.error.not_owner'];
        }

        if ($this->acceptances->forBooking($booking->id) !== null) {
            return ['ok' => false, 'error' => 'contract.error.already_accepted'];
        }

        $document = $this->contractFor($booking);
        if ($document === null) {
            return ['ok' => false, 'error' => 'contract.error.unavailable'];
        }

        $this->acceptances->record([
            'booking_id' => $booking->id,
            'document_id' => $document->id,
            'version' => $document->version === '' ? ContractBuilder::VERSION : $document->version,
            'locale' => $document->locale,
            // L'empreinte fige le document accepté : le remplacer plus tard
            // se verrait immédiatement.
            'sha256' => $document->sha256,
            'user_id' => $user->id,
            'accepted_by' => mb_substr($user->email, 0, 190),
            'ip_hash' => $this->hashAddress($ipAddress),
            'user_agent' => mb_substr($userAgent, 0, 255),
        ]);

        $this->bookings->update($booking->id, ['contract_status' => SubStatus::Done->value]);

        $this->events->record($booking->id, 'contract_accepted', [
            'version' => $document->version,
            'locale' => $document->locale,
        ], $user->id, $user->email);

        $this->audit?->record('contract.accepted', 'booking', (string) $booking->id, null, [
            'version' => $document->version,
            'locale' => $document->locale,
            'sha256' => $document->sha256,
        ], $user->id, $user->email);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * L'instantané accepté correspond-il toujours au document stocké ?
     */
    public function acceptanceIsIntact(ContractAcceptance $acceptance): bool
    {
        if ($acceptance->documentId === null) {
            return false;
        }

        $document = $this->documentRepository->find($acceptance->documentId);
        if ($document === null) {
            return false;
        }

        $contents = $this->documents->read($document);

        return $contents !== null && hash('sha256', $contents) === $acceptance->sha256;
    }

    /**
     * Une adresse IP est une donnée personnelle : la preuve d'acceptation
     * n'en conserve qu'une empreinte, suffisante pour recouper deux traces
     * sans conserver l'adresse elle-même (SECURITY.md, minimisation).
     */
    private function hashAddress(string $ipAddress): string
    {
        return $ipAddress === '' ? '' : hash('sha256', $ipAddress);
    }
}
