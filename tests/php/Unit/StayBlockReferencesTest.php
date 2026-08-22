<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Stay\StayBlockReferences;

/**
 * Carte et source d'un bloc du livret (SPECIFICATIONS.md §55).
 *
 * Deux exigences se rejoignent ici :
 *
 * 1. **une adresse saisie devient un `href`.** Ce qui n'est pas `http` ou
 *    `https` — `javascript:`, `data:` — n'a rien à faire dans un lien du
 *    livret : ce serait une injection déguisée en commodité ;
 * 2. **une information locale se périme.** Les jours de collecte et les
 *    arrêtés municipaux changent ; une source sans date de vérification ne
 *    vérifie rien.
 */
final class StayBlockReferencesTest extends TestCase
{
    public function testABlockWithoutReferencesCarriesNothing(): void
    {
        $references = StayBlockReferences::none();

        self::assertFalse($references->hasLink());
        self::assertFalse($references->hasSource());
        self::assertNull($references->checkedOn);
        self::assertSame(
            ['link_url' => '', 'link_label' => '', 'source_url' => '', 'source_checked_on' => null],
            $references->toRow()
        );
    }

    public function testAMapLinkAndItsDatedSourceAreAccepted(): void
    {
        $result = StayBlockReferences::fromInput(
            'https://maps.example/local-poubelles',
            'Le local à poubelles',
            'https://commune.example/dechets',
            '2026-03-14',
        );

        self::assertTrue($result['ok']);
        self::assertSame('', $result['error']);

        $references = $result['value'];
        self::assertTrue($references->hasLink());
        self::assertTrue($references->hasSource());
        self::assertSame('Le local à poubelles', $references->linkText());
        self::assertSame('commune.example', $references->sourceHost());
        self::assertSame('2026-03-14', $references->checkedOn);
        self::assertSame('2026-03-14', $references->checkedOnDate()?->format('Y-m-d'));
    }

    /**
     * Un lien sans intitulé reste cliquable au pouce : le domaine tient sur
     * une ligne, l'adresse complète non.
     */
    public function testALinkWithoutALabelShowsItsHost(): void
    {
        $result = StayBlockReferences::fromInput(
            'https://maps.example/tres/long/chemin?vers=le&local=a&poubelles=1',
            '',
            '',
            '',
        );

        self::assertSame('maps.example', $result['value']->linkText());
    }

    /**
     * `javascript:` dans un `href` n'est pas une carte : c'est du code.
     */
    public function testOnlyHttpAndHttpsAddressesAreAccepted(): void
    {
        foreach ([
            'javascript:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'file:///etc/passwd',
            'ftp://exemple.test/plan.pdf',
            'commune.example/dechets',
            'pas une adresse',
        ] as $hostile) {
            $link = StayBlockReferences::fromInput($hostile, '', '', '');
            self::assertFalse($link['ok'], $hostile . ' ne doit pas devenir un lien.');
            self::assertSame('stay.error.link_url', $link['error']);

            $source = StayBlockReferences::fromInput('', '', $hostile, '');
            self::assertFalse($source['ok'], $hostile . ' ne doit pas devenir une source.');
            self::assertSame('stay.error.source_url', $source['error']);
        }
    }

    /**
     * Une adresse tronquée est un lien mort qui a l'air bon : elle est
     * refusée, jamais coupée en silence.
     */
    public function testAnOverlongAddressIsRefusedRatherThanTruncated(): void
    {
        $tooLong = 'https://exemple.test/' . str_repeat('a', StayBlockReferences::URL_MAX_LENGTH);

        $result = StayBlockReferences::fromInput($tooLong, '', '', '');

        self::assertFalse($result['ok']);
        self::assertSame('stay.error.link_url', $result['error']);
    }

    public function testAnOverlongLabelIsShortenedRatherThanRefused(): void
    {
        $result = StayBlockReferences::fromInput(
            'https://maps.example/local',
            str_repeat('é', 400),
            '',
            '',
        );

        self::assertTrue($result['ok']);
        self::assertSame(
            StayBlockReferences::LABEL_MAX_LENGTH,
            mb_strlen($result['value']->linkLabel)
        );
    }

    /**
     * Une source sans date n'est pas vérifiable : la date du jour est la seule
     * honnête, comme pour la conformité (§12).
     */
    public function testASourceWithoutADateIsStampedWithToday(): void
    {
        $result = StayBlockReferences::fromInput('', '', 'https://commune.example/dechets', '', '2026-08-22');

        self::assertTrue($result['ok']);
        self::assertSame('2026-08-22', $result['value']->checkedOn);
    }

    /**
     * Une date de vérification sans source ne vérifie rien : elle est écartée
     * plutôt que d'afficher une garantie qui n'en est pas une.
     */
    public function testAVerificationDateWithoutASourceIsDropped(): void
    {
        $result = StayBlockReferences::fromInput('https://maps.example/local', '', '', '2026-03-14');

        self::assertTrue($result['ok']);
        self::assertNull($result['value']->checkedOn);
        self::assertFalse($result['value']->hasSource());
    }

    public function testAnUnreadableVerificationDateIsRefused(): void
    {
        $result = StayBlockReferences::fromInput('', '', 'https://commune.example/dechets', '14/03/2026');

        self::assertFalse($result['ok']);
        self::assertSame('stay.error.source_date', $result['error']);
    }

    /**
     * Un intitulé sans lien n'a rien à intituler.
     */
    public function testALabelWithoutALinkIsDropped(): void
    {
        $result = StayBlockReferences::fromInput('', 'Le local à poubelles', '', '');

        self::assertTrue($result['ok']);
        self::assertSame('', $result['value']->linkLabel);
        self::assertFalse($result['value']->hasLink());
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        $result = StayBlockReferences::fromInput(
            "  https://maps.example/local\n",
            '  Le local  ',
            "\thttps://commune.example/dechets ",
            ' 2026-03-14 ',
        );

        self::assertTrue($result['ok']);
        self::assertSame('https://maps.example/local', $result['value']->linkUrl);
        self::assertSame('Le local', $result['value']->linkLabel);
        self::assertSame('https://commune.example/dechets', $result['value']->sourceUrl);
        self::assertSame('2026-03-14', $result['value']->checkedOn);
    }

    /**
     * L'invariant tient aussi pour une ligne qui n'est pas passée par
     * `fromInput()` — reprise de base, migration, appel direct au dépôt. Twig
     * échappe le contenu d'un attribut, mais pas son schéma : `javascript:`
     * traverserait l'échappement intact.
     */
    public function testAnUnvalidatedAddressIsNeverHandedToAnHref(): void
    {
        $references = new StayBlockReferences(
            'javascript:alert(1)',
            'Carte',
            'data:text/html;base64,PHNjcmlwdD4=',
            '2026-03-14',
        );

        self::assertSame('', $references->safeLinkUrl());
        self::assertSame('', $references->safeSourceUrl());
        self::assertFalse($references->hasLink());
        self::assertFalse($references->hasSource());
    }

    public function testASoundAddressPassesTheRenderTimeGuard(): void
    {
        $references = new StayBlockReferences(
            'https://maps.example/local',
            'Le local',
            'http://commune.example/collecte',
            '2026-03-14',
        );

        self::assertSame('https://maps.example/local', $references->safeLinkUrl());
        self::assertSame('http://commune.example/collecte', $references->safeSourceUrl());
        self::assertTrue($references->hasLink());
        self::assertTrue($references->hasSource());
    }

    public function testARowFromTheDatabaseIsReadBack(): void
    {
        $references = StayBlockReferences::fromRow([
            'link_url' => 'https://maps.example/local',
            'link_label' => 'Le local',
            'source_url' => 'https://commune.example/dechets',
            'source_checked_on' => '2026-03-14',
        ]);

        self::assertSame('Le local', $references->linkText());
        self::assertSame('commune.example', $references->sourceHost());
        self::assertSame('2026-03-14', $references->checkedOn);
    }

    /**
     * Les colonnes n'existaient pas avant la migration 0018 : une ligne qui ne
     * les porte pas se lit sans erreur.
     */
    public function testARowWithoutTheColumnsIsReadAsEmpty(): void
    {
        $references = StayBlockReferences::fromRow(['title' => 'Déchets']);

        self::assertFalse($references->hasLink());
        self::assertFalse($references->hasSource());
    }
}
