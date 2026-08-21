<?php

declare(strict_types=1);

namespace SecondStay\Compliance;

use SecondStay\Audit\AuditTrail;
use SecondStay\Auth\User;
use SecondStay\Legal\LegalDocumentType;
use SecondStay\Legal\LegalService;

/**
 * Assistant conformité (SPECIFICATIONS.md §61).
 *
 * Il ne décide rien à la place du propriétaire et ne donne aucun conseil
 * juridique : pour chaque sujet il fournit une définition, dit où chercher,
 * explique l'impact, et **garde la trace** de ce que le propriétaire a
 * constaté — avec la source et la date de vérification. C'est un carnet de
 * bord, pas un oracle.
 *
 * Deux sujets sont alimentés par le produit lui-même : la publication des
 * textes légaux, et l'échéance de revue. Les laisser à la saisie manuelle
 * ferait mentir l'assistant dès la première publication oubliée.
 */
final class ComplianceService
{
    /** Durée de validité par défaut d'une vérification, en mois. */
    public const DEFAULT_REVIEW_MONTHS = 12;

    public function __construct(
        private readonly ComplianceRepository $items,
        private readonly LegalService $legal,
        private readonly ?AuditTrail $audit = null,
    ) {
    }

    /**
     * @return list<ComplianceItem>
     */
    public function all(): array
    {
        return $this->items->all();
    }

    public function find(ComplianceTopic $topic): ComplianceItem
    {
        return $this->items->find($topic);
    }

    /**
     * Enregistre ce que le propriétaire a constaté pour un sujet.
     *
     * @param array{
     *     status?: string,
     *     value?: string,
     *     notes?: string,
     *     source_url?: string,
     *     last_verified?: string,
     *     next_review?: string,
     *     evidence_id?: int|null
     * } $input
     *
     * @return array{ok: bool, error: string}
     */
    public function save(ComplianceTopic $topic, array $input, ?User $actor = null): array
    {
        $status = ComplianceStatus::fromString($input['status'] ?? '');
        $sourceUrl = trim($input['source_url'] ?? '');

        if ($sourceUrl !== '' && !$this->isAcceptableSource($sourceUrl)) {
            // Une « source officielle » qui n'est pas une adresse web n'en est
            // pas une : mieux vaut le dire que de stocker n'importe quoi.
            return ['ok' => false, 'error' => 'compliance.error.source'];
        }

        $lastVerified = $this->normaliseDate($input['last_verified'] ?? '');
        $nextReview = $this->normaliseDate($input['next_review'] ?? '');

        // Un sujet déclaré conforme sans date de vérification n'est pas
        // vérifiable : la date du jour est alors la seule honnête.
        if ($status === ComplianceStatus::Compliant && $lastVerified === null) {
            $lastVerified = gmdate('Y-m-d');
        }

        if ($nextReview === null && $lastVerified !== null && $status !== ComplianceStatus::NotApplicable) {
            $nextReview = $this->defaultReview($lastVerified);
        }

        $previous = $this->items->find($topic);

        $this->items->save($topic, [
            'status' => $status->value,
            'value' => mb_substr(trim($input['value'] ?? ''), 0, 190),
            'notes' => trim($input['notes'] ?? '') === '' ? null : mb_substr(trim($input['notes']), 0, 8000),
            'source_url' => mb_substr($sourceUrl, 0, 500),
            'last_verified' => $lastVerified,
            'next_review' => $nextReview,
            'evidence_id' => $input['evidence_id'] ?? $previous->evidenceId,
        ], $actor?->id);

        $this->audit?->record('compliance.saved', 'compliance_item', $topic->value, [
            'status' => $previous->status->value,
        ], [
            'status' => $status->value,
            'last_verified' => $lastVerified,
        ], $actor?->id, $actor === null ? '' : $actor->email);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Attache une pièce justificative à un sujet.
     */
    public function attachEvidence(ComplianceTopic $topic, int $documentId, ?User $actor = null): void
    {
        $this->items->save($topic, ['evidence_id' => $documentId], $actor?->id);

        $this->audit?->record('compliance.evidence', 'compliance_item', $topic->value, null, [
            'document' => $documentId,
        ], $actor?->id, $actor === null ? '' : $actor->email);
    }

    /**
     * Sujets réclamant une action, pour le tableau « À faire ».
     *
     * @return list<ComplianceItem>
     */
    public function outstanding(?string $today = null): array
    {
        return $this->items->outstanding($today);
    }

    /**
     * Vue d'ensemble : combien de sujets dans chaque état.
     *
     * @return array{compliant: int, to_verify: int, not_applicable: int, overdue: int, total: int}
     */
    public function summary(?string $today = null): array
    {
        $summary = ['compliant' => 0, 'to_verify' => 0, 'not_applicable' => 0, 'overdue' => 0, 'total' => 0];

        foreach ($this->items->all() as $item) {
            $summary[$item->status->value]++;
            $summary['total']++;

            if ($item->isOverdue($today)) {
                $summary['overdue']++;
            }
        }

        return $summary;
    }

    /**
     * État de la publication des textes légaux, langue par langue.
     *
     * @return list<array{type: LegalDocumentType, version: string, locales: list<string>}>
     */
    public function legalCoverage(): array
    {
        $coverage = [];

        foreach ([LegalDocumentType::Terms, LegalDocumentType::Privacy] as $type) {
            foreach ($this->legal->coverage($type) as $version => $locales) {
                $coverage[] = ['type' => $type, 'version' => (string) $version, 'locales' => $locales];
            }
        }

        return $coverage;
    }

    // --- Interne ------------------------------------------------------------------

    /**
     * Échéance par défaut : un an après la vérification.
     */
    private function defaultReview(string $lastVerified): string
    {
        return (new \DateTimeImmutable($lastVerified . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify('+' . self::DEFAULT_REVIEW_MONTHS . ' months')
            ->format('Y-m-d');
    }

    private function normaliseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date === false ? null : $date->format('Y-m-d');
    }

    /**
     * Une source doit être une adresse web publique.
     */
    private function isAcceptableSource(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' || $scheme === 'http';
    }
}
