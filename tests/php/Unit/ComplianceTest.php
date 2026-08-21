<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Compliance\ComplianceItem;
use SecondStay\Compliance\ComplianceStatus;
use SecondStay\Compliance\ComplianceTopic;
use SecondStay\Legal\LegalDocument;
use SecondStay\Legal\LegalDocumentType;
use SecondStay\Tax\TouristTaxRule;

/**
 * Règles de conformité, de textes légaux et de barèmes, sans base de données.
 *
 * Ce qui est vérifié ici est le cœur de l'itération : un sujet « à vérifier »
 * ou dont la revue est dépassée réclame une action, une version publiée est
 * immuable, et un barème daté ne s'applique qu'à sa période.
 */
final class ComplianceTest extends TestCase
{
    // --- Assistant conformité --------------------------------------------------------

    public function testEverySpecifiedTopicExists(): void
    {
        $codes = array_map(
            static fn (ComplianceTopic $topic): string => $topic->value,
            ComplianceTopic::cases()
        );

        // La liste vient de SPECIFICATIONS.md §62 : en retirer un sujet
        // reviendrait à ne plus poser la question au propriétaire.
        self::assertSame([
            'furnished_tourism',
            'declaration',
            'siret',
            'owner_status',
            'residence_kind',
            'classification',
            'energy_diagnosis',
            'change_of_use',
            'tourist_tax',
            'police_record',
            'contract',
            'cancellation',
            'mediation',
            'insurance',
            'local_risks',
            'clearing',
            'winter_equipment',
            'waste',
        ], $codes);
    }

    public function testAnUnknownStatusIsNeverTakenForCompliant(): void
    {
        self::assertSame(ComplianceStatus::ToVerify, ComplianceStatus::fromString('parfait'));
        self::assertSame(ComplianceStatus::ToVerify, ComplianceStatus::fromString(''));
        self::assertTrue(ComplianceStatus::ToVerify->needsAction());
        self::assertFalse(ComplianceStatus::Compliant->needsAction());
        self::assertFalse(ComplianceStatus::NotApplicable->needsAction());
    }

    public function testAnOverdueReviewDemandsActionEvenWhenCompliant(): void
    {
        $item = $this->item(ComplianceStatus::Compliant, '2025-01-10', '2026-01-10');

        self::assertTrue($item->isOverdue('2026-06-01'));
        self::assertTrue($item->needsAction('2026-06-01'));

        // Le jour même de l'échéance, la revue n'est pas encore dépassée.
        self::assertFalse($item->isOverdue('2026-01-10'));
        self::assertFalse($item->needsAction('2026-01-10'));
    }

    public function testASubjectDeclaredNotApplicableDoesNotExpire(): void
    {
        // Il n'y a rien à revoir sur un sujet qui ne concerne pas le logement.
        $item = $this->item(ComplianceStatus::NotApplicable, '2020-01-01', '2021-01-01');

        self::assertFalse($item->isOverdue('2026-06-01'));
        self::assertFalse($item->needsAction('2026-06-01'));
    }

    public function testASubjectWithoutReviewDateNeverExpires(): void
    {
        $item = $this->item(ComplianceStatus::Compliant, '2026-01-10', null);

        self::assertFalse($item->isOverdue('2030-01-01'));
    }

    public function testOnlyIdentifierTopicsExpectAValue(): void
    {
        self::assertTrue(ComplianceTopic::Siret->expectsValue());
        self::assertTrue(ComplianceTopic::Classification->expectsValue());
        // « Débroussaillement » est une situation, pas un numéro.
        self::assertFalse(ComplianceTopic::Clearing->expectsValue());
        self::assertFalse(ComplianceTopic::Waste->expectsValue());
    }

    public function testTopicsHandledElsewhereLinkToTheirOwnScreen(): void
    {
        self::assertSame('admin.tax', ComplianceTopic::TouristTax->managedRoute());
        self::assertSame('admin.police', ComplianceTopic::PoliceRecord->managedRoute());
        self::assertSame('', ComplianceTopic::Insurance->managedRoute());
    }

    // --- Textes légaux -----------------------------------------------------------------

    public function testAPublishedTextCarriesItsOwnFingerprint(): void
    {
        $body = "Conditions générales\nVersion d’essai.";
        $document = new LegalDocument(
            1,
            LegalDocumentType::Terms,
            'fr',
            '2026-01',
            'Conditions générales',
            $body,
            hash('sha256', $body),
            '2026-01-15 10:00:00',
            null,
        );

        self::assertTrue($document->isIntact());
    }

    public function testAnAlteredTextIsDetected(): void
    {
        // Une version publiée est une preuve : si le corps ne correspond plus
        // à son empreinte, il ne prouve plus rien.
        $document = new LegalDocument(
            1,
            LegalDocumentType::Terms,
            'fr',
            '2026-01',
            'Conditions générales',
            'Texte modifié après coup.',
            hash('sha256', 'Texte d’origine.'),
            '2026-01-15 10:00:00',
            null,
        );

        self::assertFalse($document->isIntact());
    }

    public function testTheTextsAcceptedAtEachMomentAreDistinct(): void
    {
        self::assertSame(
            [LegalDocumentType::Terms, LegalDocumentType::HouseRules],
            LegalDocumentType::acceptedOnBooking()
        );
        self::assertSame(
            [LegalDocumentType::Terms, LegalDocumentType::Privacy],
            LegalDocumentType::acceptedOnSignup()
        );
    }

    // --- Barèmes de taxe de séjour -----------------------------------------------------

    /**
     * @return list<array{string, bool}>
     */
    public static function days(): array
    {
        return [
            ['2025-12-31', false],
            ['2026-01-01', true],
            ['2026-06-15', true],
            ['2026-12-31', true],
            ['2027-01-01', false],
        ];
    }

    #[DataProvider('days')]
    public function testARuleAppliesOnlyWithinItsPeriod(string $day, bool $expected): void
    {
        $rule = $this->rule('2026-01-01', '2026-12-31');

        // Les deux bornes sont incluses : une règle qui court jusqu'au
        // 31 décembre s'applique bien le 31 décembre.
        self::assertSame($expected, $rule->appliesOn($day));
    }

    public function testAnOpenEndedRuleNeverStops(): void
    {
        $rule = $this->rule('2026-01-01', null);

        self::assertFalse($rule->appliesOn('2025-12-31'));
        self::assertTrue($rule->appliesOn('2099-01-01'));
    }

    public function testTheCapIsAppliedPerStay(): void
    {
        $rule = new TouristTaxRule(1, 'Commune', 'unclassified', '2026-01-01', null, 150, 1000, 18, '', '');

        // 2 adultes × 7 nuits × 1,50 € = 21 €, plafonné à 10 €.
        self::assertSame(1000, $rule->compute(2, 7));
        // Sous le plafond, le calcul est intégral.
        self::assertSame(450, $rule->compute(1, 3));
    }

    public function testNothingIsDueWithoutTaxablePersonsOrNights(): void
    {
        $rule = $this->rule('2026-01-01', null);

        self::assertSame(0, $rule->compute(0, 7));
        self::assertSame(0, $rule->compute(2, 0));
        self::assertSame(0, $rule->compute(-3, 7));
    }

    // --- Fabriques ---------------------------------------------------------------------

    private function item(ComplianceStatus $status, ?string $verified, ?string $review): ComplianceItem
    {
        return new ComplianceItem(
            1,
            ComplianceTopic::Insurance,
            $status,
            '',
            '',
            '',
            $verified,
            $review,
            null,
            '2026-01-10 09:00:00',
        );
    }

    private function rule(string $from, ?string $to): TouristTaxRule
    {
        return new TouristTaxRule(1, 'Commune', 'unclassified', $from, $to, 150, 0, 18, '', '');
    }
}
