<?php

declare(strict_types=1);

namespace SecondStay\Compliance;

/**
 * Sujets de l'assistant conformité (SPECIFICATIONS.md §62).
 *
 * La liste est celle de la spécification, dans l'ordre où un propriétaire les
 * rencontre : d'abord ce qui définit son activité, puis ce qui l'encadre,
 * enfin ce qui concerne le séjour lui-même.
 *
 * Ce que le produit fournit pour chaque sujet est **du texte** : définition,
 * applicabilité, où trouver l'information, impact. Ce qui est propre à ce
 * logement — statut, valeur, source, date de vérification — vit en base, saisi
 * par le propriétaire. Le produit ne prétend jamais savoir à sa place.
 */
enum ComplianceTopic: string
{
    case FurnishedTourism = 'furnished_tourism';
    case Declaration = 'declaration';
    case Siret = 'siret';
    case OwnerStatus = 'owner_status';
    case ResidenceKind = 'residence_kind';
    case Classification = 'classification';
    case EnergyDiagnosis = 'energy_diagnosis';
    case ChangeOfUse = 'change_of_use';
    case TouristTax = 'tourist_tax';
    case PoliceRecord = 'police_record';
    case Contract = 'contract';
    case Cancellation = 'cancellation';
    case Mediation = 'mediation';
    case Insurance = 'insurance';
    case LocalRisks = 'local_risks';
    case Clearing = 'clearing';
    case WinterEquipment = 'winter_equipment';
    case Waste = 'waste';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::FurnishedTourism;
    }

    /**
     * Ordre d'affichage.
     */
    public function position(): int
    {
        return array_search($this, self::cases(), true) * 10 + 10;
    }

    public function labelKey(): string
    {
        return 'compliance.topic.' . $this->value . '.label';
    }

    public function definitionKey(): string
    {
        return 'compliance.topic.' . $this->value . '.definition';
    }

    public function applicabilityKey(): string
    {
        return 'compliance.topic.' . $this->value . '.applicability';
    }

    public function whereKey(): string
    {
        return 'compliance.topic.' . $this->value . '.where';
    }

    public function impactKey(): string
    {
        return 'compliance.topic.' . $this->value . '.impact';
    }

    /**
     * Sujets dont une valeur chiffrée ou textuelle est attendue.
     *
     * Pour les autres, la case « valeur » n'aurait aucun sens : le sujet est
     * une situation, pas un numéro.
     */
    public function expectsValue(): bool
    {
        return match ($this) {
            self::Siret, self::Declaration, self::Classification,
            self::EnergyDiagnosis, self::Insurance, self::Mediation => true,
            default => false,
        };
    }

    /**
     * Le sujet est-il piloté par une autre partie du produit ?
     *
     * La taxe de séjour a son moteur, la fiche de police son registre, le
     * contrat son générateur : l'assistant y renvoie plutôt que de demander
     * une saisie qui existerait alors deux fois.
     */
    public function managedRoute(): string
    {
        return match ($this) {
            self::TouristTax => 'admin.tax',
            self::PoliceRecord => 'admin.police',
            self::Contract => 'admin.documents',
            default => '',
        };
    }
}
