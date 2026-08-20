<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use SecondStay\Audit\AuditTrail;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentRepository;
use SecondStay\Document\DocumentService;
use SecondStay\Document\DocumentSource;
use SecondStay\Logging\Logger;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Stockage des documents.
 *
 * Le point sensible n'est pas l'écriture : c'est ce qui n'est **pas** écrit —
 * aucun fichier sous le document root, aucun nom d'origine sur disque, aucun
 * type accepté sur la foi de son extension.
 */
final class DocumentServiceTest extends DatabaseTestCase
{
    private DocumentService $documents;

    private DocumentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new DocumentRepository($this->database);
        $this->documents = new DocumentService(
            $this->repository,
            $this->paths,
            new Logger($this->storagePath . '/logs'),
            new AuditTrail($this->database),
        );
    }

    public function testAPdfIsStoredOutsideTheDocumentRoot(): void
    {
        $result = $this->documents->store(
            $this->pdf(),
            'contrat.pdf',
            DocumentKind::Contract,
            DocumentSource::Generated,
        );

        self::assertTrue($result['ok'], $result['error']);
        $document = $result['document'];
        self::assertNotNull($document);

        self::assertSame('application/pdf', $document->mime);
        self::assertStringStartsWith('documents/', $document->storagePath);
        self::assertStringNotContainsString('..', $document->storagePath);

        $absolute = $this->documents->absolutePath($document);
        self::assertNotNull($absolute);
        self::assertFileExists($absolute);

        // `assertStringStartsWith` exige un préfixe non vide que PHPStan ne
        // peut pas déduire d'un chemin construit : la comparaison est faite
        // directement, ce qui vérifie exactement la même chose.
        self::assertTrue(
            str_starts_with($absolute, $this->paths->storage('documents')),
            'Le fichier doit vivre sous storage/documents.'
        );
        self::assertStringNotContainsString(self::projectRoot() . '/public', $absolute);
    }

    public function testTheStoredFileIsNamedAfterItsFingerprintNotItsOriginalName(): void
    {
        $contents = $this->pdf();
        $result = $this->documents->store(
            $contents,
            '../../etc/passwd.pdf',
            DocumentKind::Attachment,
            DocumentSource::Mail,
        );

        $document = $result['document'];
        self::assertNotNull($document);

        self::assertSame(hash('sha256', $contents), $document->sha256);
        self::assertStringContainsString($document->sha256, $document->storagePath);
        self::assertStringNotContainsString('passwd', $document->storagePath);
        self::assertStringNotContainsString('..', $document->storagePath);
        // Le nom affiché reste lisible, mais dépouillé de tout chemin.
        self::assertSame('passwd.pdf', $document->filename);
    }

    public function testTheContentIsReadBackUnchanged(): void
    {
        $contents = $this->pdf() . str_repeat("\x00\xff", 100);
        $result = $this->documents->store($contents, 'a.pdf', DocumentKind::Other, DocumentSource::Upload);

        $document = $result['document'];
        self::assertNotNull($document);
        self::assertSame($contents, $this->documents->read($document));
    }

    public function testTheSameFileTwiceProducesASingleDocument(): void
    {
        $contents = $this->pdf();

        $bookingId = $this->createBooking();

        $first = $this->documents->store($contents, 'a.pdf', DocumentKind::Attachment, DocumentSource::Mail, $bookingId);
        $second = $this->documents->store($contents, 'a.pdf', DocumentKind::Attachment, DocumentSource::Mail, $bookingId);

        self::assertNotNull($first['document']);
        self::assertNotNull($second['document']);
        self::assertSame($first['document']->id, $second['document']->id);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function refusedContents(): array
    {
        return [
            // Un script PHP renommé en PDF reste un script PHP.
            ['<?php echo "compromis"; ?>', 'contrat.pdf'],
            ['#!/bin/sh' . "\n" . 'rm -rf /', 'script.pdf'],
            ['<html><body><script>alert(1)</script></body></html>', 'page.pdf'],
            ['MZ' . str_repeat("\x00", 100), 'installeur.pdf'],
        ];
    }

    #[DataProvider('refusedContents')]
    public function testContentIsJudgedOnWhatItIsNotOnItsExtension(string $contents, string $filename): void
    {
        $result = $this->documents->store($contents, $filename, DocumentKind::Other, DocumentSource::Mail);

        self::assertFalse($result['ok'], 'Un ' . $filename . ' prétendu ne doit pas passer.');
        self::assertSame('document.error.type', $result['error']);
        self::assertNull($result['document']);
    }

    public function testAnEmptyOrOversizedFileIsRefused(): void
    {
        self::assertSame(
            'document.error.empty',
            $this->documents->store('', 'vide.pdf', DocumentKind::Other, DocumentSource::Upload)['error']
        );

        $huge = $this->pdf() . str_repeat('a', DocumentService::MAX_BYTES);
        self::assertSame(
            'document.error.too_large',
            $this->documents->store($huge, 'gros.pdf', DocumentKind::Other, DocumentSource::Upload)['error']
        );
    }

    public function testACorruptedStoragePathNeverEscapesTheStorageDirectory(): void
    {
        $result = $this->documents->store($this->pdf(), 'a.pdf', DocumentKind::Other, DocumentSource::Upload);
        $document = $result['document'];
        self::assertNotNull($document);

        // Une valeur en base est modifiée pour pointer hors du stockage.
        $this->database->update(
            'document',
            ['storage_path' => '../../composer.json'],
            ['id' => $document->id]
        );

        $tampered = $this->repository->find($document->id);
        self::assertNotNull($tampered);

        self::assertNull($this->documents->absolutePath($tampered));
        self::assertNull($this->documents->read($tampered));
    }

    public function testDeletingRemovesTheRecordAndTheOrphanedFile(): void
    {
        $result = $this->documents->store($this->pdf(), 'a.pdf', DocumentKind::Other, DocumentSource::Upload);
        $document = $result['document'];
        self::assertNotNull($document);

        $path = $this->documents->absolutePath($document);
        self::assertNotNull($path);

        $this->documents->delete($document);

        self::assertNull($this->repository->find($document->id));
        self::assertFileDoesNotExist($path);
    }

    public function testDocumentsAreListedNewestFirstForABooking(): void
    {
        $bookingId = $this->createBooking();

        foreach (['a', 'b', 'c'] as $suffix) {
            $this->documents->store(
                $this->pdf() . $suffix,
                $suffix . '.pdf',
                DocumentKind::Attachment,
                DocumentSource::Mail,
                $bookingId,
            );
        }

        $documents = $this->repository->forBooking($bookingId);

        self::assertCount(3, $documents);
        self::assertSame('c.pdf', $documents[0]->filename);
    }

    public function testTheLatestDocumentOfAKindIsFound(): void
    {
        $bookingId = $this->createBooking();

        $this->documents->store($this->pdf() . '1', 'c1.pdf', DocumentKind::Contract, DocumentSource::Generated, $bookingId);
        $this->documents->store($this->pdf() . '2', 'c2.pdf', DocumentKind::Contract, DocumentSource::Generated, $bookingId);
        $this->documents->store($this->pdf() . '3', 'p.pdf', DocumentKind::Proof, DocumentSource::Mail, $bookingId);

        $latest = $this->repository->latestKind($bookingId, DocumentKind::Contract);

        self::assertNotNull($latest);
        self::assertSame('c2.pdf', $latest->filename);
    }

    public function testAnIncidentDocumentIsNotVisibleToTheCustomer(): void
    {
        self::assertFalse(DocumentKind::Incident->visibleToCustomer());
        self::assertTrue(DocumentKind::Contract->visibleToCustomer());
        self::assertTrue(DocumentKind::SignedContract->visibleToCustomer());
    }

    /**
     * @return list<array{string, string}>
     */
    public static function filenames(): array
    {
        return [
            ['contrat.pdf', 'contrat.pdf'],
            ['/etc/passwd', 'passwd.pdf'],
            ['..\\..\\windows\\system32\\a.txt', 'a.pdf'],
            ["nom\navec\nsauts.pdf", 'nomavecsauts.pdf'],
            ['///', 'document.pdf'],
            ['', 'document.pdf'],
            ['contrat signé été.pdf', 'contrat signé été.pdf'],
            [str_repeat('a', 300) . '.pdf', str_repeat('a', 120) . '.pdf'],
        ];
    }

    #[DataProvider('filenames')]
    public function testTheDisplayedNameIsAlwaysSafe(string $given, string $expected): void
    {
        self::assertSame($expected, $this->documents->safeFilename($given, 'pdf'));
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< >>\n%%EOF\n";
    }

    private function createBooking(): int
    {
        return $this->database->insert('booking', [
            'reference' => 'ABCD-2345',
            'status' => 'to_confirm',
            'arrival' => '2026-07-04',
            'departure' => '2026-07-11',
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'locale' => 'fr',
            'guest_email' => 'claire@example.test',
            'guest_name' => 'Claire Dubois',
            'total_cents' => 100000,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
