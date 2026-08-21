<?php

declare(strict_types=1);

namespace SecondStay\Compliance;

/**
 * Statut d'un sujet de conformité (SPECIFICATIONS.md §61).
 *
 * Trois valeurs, et pas une de plus : « conforme », « à vérifier », « non
 * applicable ». Un quatrième statut du genre « en cours » laisserait un sujet
 * dans un entre-deux confortable, ce qui est exactement ce que l'assistant
 * doit empêcher.
 */
enum ComplianceStatus: string
{
    case Compliant = 'compliant';
    case ToVerify = 'to_verify';
    case NotApplicable = 'not_applicable';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::ToVerify;
    }

    /**
     * Ce sujet réclame-t-il encore une action ?
     */
    public function needsAction(): bool
    {
        return $this === self::ToVerify;
    }

    public function labelKey(): string
    {
        return 'compliance.status.' . $this->value;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Compliant => 'text-bg-success',
            self::ToVerify => 'text-bg-warning',
            self::NotApplicable => 'text-bg-secondary',
        };
    }
}
