<?php

declare(strict_types=1);

namespace SecondStay\Document;

use RuntimeException;
use SecondStay\Audit\AuditTrail;
use SecondStay\Core\Paths;
use SecondStay\Logging\Logger;
use SecondStay\Quota\QuotaService;

/**
 * Stockage des documents d'un séjour.
 *
 * Trois règles gouvernent ce service :
 *
 * 1. un document n'est **jamais** écrit sous le document root : il est servi
 *    par l'application, après contrôle d'accès, jamais par le serveur web ;
 * 2. le nom d'origine est conservé pour l'affichage, mais **jamais** utilisé
 *    comme nom de fichier : le nom sur disque est dérivé de l'empreinte ;
 * 3. le type MIME est déduit du contenu, pas de l'extension ni de ce que le
 *    client annonce.
 */
final class DocumentService
{
    /** Un contrat signé renvoyé en pièce jointe reste raisonnablement petit. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * Types acceptés.
     *
     * La liste est fermée : accepter n'importe quoi reviendrait à offrir un
     * hébergement de fichiers arbitraires à qui sait écrire à la boîte du
     * logement.
     *
     * @var array<string, string> MIME => extension
     */
    private const ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    ];

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly Paths $paths,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
        private readonly ?QuotaService $quotas = null,
    ) {
    }

    /**
     * Enregistre un contenu déjà en mémoire.
     *
     * @return array{ok: bool, document: Document|null, error: string}
     */
    public function store(
        string $contents,
        string $filename,
        DocumentKind $kind,
        DocumentSource $source,
        ?int $bookingId = null,
        ?int $mailId = null,
        ?int $uploadedBy = null,
        string $sender = '',
        string $locale = 'fr',
        string $version = '',
    ): array {
        if ($contents === '') {
            return ['ok' => false, 'document' => null, 'error' => 'document.error.empty'];
        }

        if (strlen($contents) > self::MAX_BYTES) {
            return ['ok' => false, 'document' => null, 'error' => 'document.error.too_large'];
        }

        // Le quota est vérifié **avant** d'écrire : un disque plein casse la
        // sauvegarde qui aurait permis de s'en sortir.
        if ($this->quotas !== null && !$this->quotas->allows('documents', strlen($contents))) {
            return ['ok' => false, 'document' => null, 'error' => 'quota.error.documents'];
        }

        $mime = $this->detectMime($contents);
        if ($mime === null) {
            $this->logger->warning('document', 'Type de document refusé', ['filename' => $filename]);

            return ['ok' => false, 'document' => null, 'error' => 'document.error.type'];
        }

        $sha256 = hash('sha256', $contents);

        // Un même fichier reçu deux fois ne crée pas deux documents.
        $existing = $this->documents->findByHash($bookingId, $sha256);
        if ($existing !== null) {
            return ['ok' => true, 'document' => $existing, 'error' => ''];
        }

        $relative = $this->relativePath($sha256, self::ALLOWED[$mime]);
        $absolute = $this->paths->storage($relative);

        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire de documents : ' . $directory);
        }

        if (!is_file($absolute) && file_put_contents($absolute, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’écrire le document : ' . $absolute);
        }

        @chmod($absolute, 0o640);

        $id = $this->documents->create([
            'booking_id' => $bookingId,
            'kind' => $kind->value,
            'source' => $source->value,
            'filename' => $this->safeFilename($filename, self::ALLOWED[$mime]),
            'mime' => $mime,
            'size_bytes' => strlen($contents),
            'sha256' => $sha256,
            'storage_path' => $relative,
            'locale' => $locale,
            'version' => $version,
            'mail_id' => $mailId,
            'uploaded_by' => $uploadedBy,
            'sender' => mb_substr($sender, 0, 190),
        ]);

        $this->audit?->record('document.stored', 'document', (string) $id, null, [
            'kind' => $kind->value,
            'source' => $source->value,
            'sha256' => $sha256,
        ], $uploadedBy);

        $document = $this->documents->find($id);

        return ['ok' => true, 'document' => $document, 'error' => ''];
    }

    /**
     * Rattache après coup un document à un séjour.
     *
     * Une pièce jointe reçue avant que son courrier ne soit rattaché reste
     * sans séjour : la rattacher plus tard fait partie du rattachement manuel.
     */
    public function attachToBooking(int $documentId, int $bookingId): void
    {
        $this->documents->attachToBooking($documentId, $bookingId);
    }

    /**
     * @return list<int>
     */
    public function documentIdsForMail(int $mailId): array
    {
        return array_map(
            static fn (Document $document): int => $document->id,
            $this->documents->forMail($mailId)
        );
    }

    /**
     * Contenu d'un document, ou `null` si le fichier a disparu du stockage.
     */
    public function read(Document $document): ?string
    {
        $path = $this->absolutePath($document);
        if ($path === null || !is_file($path)) {
            $this->logger->error('document', 'Fichier de document introuvable', ['id' => $document->id]);

            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Chemin absolu, une fois vérifié qu'il reste sous le stockage.
     *
     * Le chemin vient de la base : le confronter à la racine du stockage
     * empêche qu'une valeur corrompue fasse lire un fichier arbitraire.
     */
    public function absolutePath(Document $document): ?string
    {
        $root = realpath($this->paths->storage('documents'));
        if ($root === false) {
            return null;
        }

        $candidate = $this->paths->storage($document->storagePath);
        $resolved = realpath($candidate);

        if ($resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Supprime le document et, si plus rien ne le référence, son fichier.
     */
    public function delete(Document $document, ?int $actorId = null): void
    {
        $path = $this->absolutePath($document);
        $this->documents->delete($document->id);

        // Le fichier est nommé par son empreinte : il peut être partagé par
        // plusieurs enregistrements, y compris sur d'autres séjours. On ne
        // l'efface qu'une fois réellement orphelin — sinon supprimer un
        // document en rendrait un autre illisible.
        if ($path !== null && !$this->documents->existsWithHash($document->sha256)) {
            @unlink($path);
        }

        $this->audit?->record('document.deleted', 'document', (string) $document->id, [
            'filename' => $document->filename,
        ], null, $actorId);
    }

    /**
     * Type MIME déduit du contenu réel.
     *
     * Ni l'extension ni l'en-tête annoncé par l'expéditeur ne sont crus : une
     * pièce jointe nommée « contrat.pdf » peut être tout autre chose.
     */
    public function detectMime(string $contents): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents);

        if ($detected === false) {
            return null;
        }

        // `finfo` renvoie parfois un type générique pour les formats zippés.
        if ($detected === 'application/zip') {
            $detected = $this->refineZip($contents) ?? $detected;
        }

        return isset(self::ALLOWED[$detected]) ? $detected : null;
    }

    /**
     * @return list<string>
     */
    public function allowedMimeTypes(): array
    {
        return array_keys(self::ALLOWED);
    }

    // --- Internes ---------------------------------------------------------------

    /**
     * Les formats OpenXML et OpenDocument sont des archives ZIP : leur nature
     * réelle se lit dans leur premier fichier.
     */
    private function refineZip(string $contents): ?string
    {
        if (str_contains(substr($contents, 0, 512), 'mimetypeapplication/vnd.oasis.opendocument.text')) {
            return 'application/vnd.oasis.opendocument.text';
        }
        if (str_contains(substr($contents, 0, 512), 'mimetypeapplication/vnd.oasis.opendocument.spreadsheet')) {
            return 'application/vnd.oasis.opendocument.spreadsheet';
        }
        if (str_contains($contents, 'word/document.xml')) {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
        if (str_contains($contents, 'xl/workbook.xml')) {
            return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }

        return null;
    }

    /**
     * Chemin sur disque, dérivé de l'empreinte et réparti en sous-répertoires.
     *
     * Le nom d'origine n'y figure pas : il n'aurait aucune raison d'être sûr,
     * et des milliers de fichiers dans un seul répertoire ralentiraient tout.
     */
    private function relativePath(string $sha256, string $extension): string
    {
        return sprintf('documents/%s/%s/%s.%s', substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256, $extension);
    }

    /**
     * Nom affiché : dépouillé de tout chemin, borné, et pourvu de l'extension
     * correspondant au type réellement détecté.
     */
    public function safeFilename(string $filename, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = (string) preg_replace('/[\x00-\x1f\x7f]/', '', $name);
        $name = trim($name);

        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^\p{L}\p{N} ._-]+/u', '-', $base);
        $base = trim((string) preg_replace('/-{2,}/', '-', $base), '-. ');

        if ($base === '' || preg_match('/[\p{L}\p{N}]/u', $base) !== 1) {
            $base = 'document';
        }

        if (mb_strlen($base) > 120) {
            $base = mb_substr($base, 0, 120);
        }

        return $base . '.' . $extension;
    }
}
