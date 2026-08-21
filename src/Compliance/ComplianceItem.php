<?php

declare(strict_types=1);

namespace SecondStay\Compliance;

use DateTimeImmutable;
use DateTimeZone;

/**
 * L'état d'un sujet de conformité pour **ce** logement.
 */
final class ComplianceItem
{
    public function __construct(
        public readonly int $id,
        public readonly ComplianceTopic $topic,
        public readonly ComplianceStatus $status,
        public readonly string $value,
        public readonly string $notes,
        public readonly string $sourceUrl,
        public readonly ?string $lastVerified,
        public readonly ?string $nextReview,
        public readonly ?int $evidenceId,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            ComplianceTopic::fromString((string) $row['topic']),
            ComplianceStatus::fromString((string) $row['status']),
            (string) $row['value'],
            (string) ($row['notes'] ?? ''),
            (string) $row['source_url'],
            $row['last_verified'] === null ? null : (string) $row['last_verified'],
            $row['next_review'] === null ? null : (string) $row['next_review'],
            $row['evidence_id'] === null ? null : (int) $row['evidence_id'],
            (string) $row['updated_at'],
        );
    }

    /**
     * L'échéance de revue est-elle dépassée ?
     *
     * Un sujet « non applicable » ne se périme pas : il n'y a rien à revoir.
     */
    public function isOverdue(?string $today = null): bool
    {
        if ($this->nextReview === null || $this->status === ComplianceStatus::NotApplicable) {
            return false;
        }

        return $this->nextReview < ($today ?? gmdate('Y-m-d'));
    }

    /**
     * Ce sujet demande-t-il une action : à vérifier, ou revue dépassée ?
     */
    public function needsAction(?string $today = null): bool
    {
        return $this->status->needsAction() || $this->isOverdue($today);
    }

    public function lastVerifiedDate(): ?DateTimeImmutable
    {
        return $this->lastVerified === null
            ? null
            : new DateTimeImmutable($this->lastVerified . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public function nextReviewDate(): ?DateTimeImmutable
    {
        return $this->nextReview === null
            ? null
            : new DateTimeImmutable($this->nextReview . ' 00:00:00', new DateTimeZone('UTC'));
    }
}
