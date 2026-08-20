<?php

declare(strict_types=1);

namespace SecondStay\Document;

/**
 * Nature d'un document rattaché à un séjour (SPECIFICATIONS.md §41).
 */
enum DocumentKind: string
{
    case Contract = 'contract';
    case SignedContract = 'signed_contract';
    case Description = 'description';
    case Receipt = 'receipt';
    case Invoice = 'invoice';
    case Proof = 'proof';
    case Inventory = 'inventory';
    case Incident = 'incident';
    case Attachment = 'attachment';
    case Other = 'other';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Other;
    }

    /**
     * Classement proposé pour une pièce jointe reçue par e-mail
     * (SPECIFICATIONS.md §38).
     *
     * @return list<self>
     */
    public static function inboundChoices(): array
    {
        return [self::SignedContract, self::Proof, self::Receipt, self::Other];
    }

    /**
     * Un document que le client peut consulter depuis son espace.
     */
    public function visibleToCustomer(): bool
    {
        return match ($this) {
            self::Incident => false,
            default => true,
        };
    }

    public function labelKey(): string
    {
        return 'document.kind.' . $this->value;
    }
}
