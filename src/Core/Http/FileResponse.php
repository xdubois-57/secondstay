<?php

declare(strict_types=1);

namespace SecondStay\Core\Http;

use RuntimeException;

/**
 * Reponse servant un fichier prive depuis storage/ via un endpoint controle.
 */
final class FileResponse extends Response
{
    public function __construct(
        private readonly string $absolutePath,
        private readonly string $downloadName,
        string $mimeType = 'application/octet-stream',
        private readonly bool $inline = false,
    ) {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('Fichier introuvable.');
        }

        $disposition = $inline ? 'inline' : 'attachment';
        parent::__construct('', 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) (filesize($absolutePath) ?: 0),
            'Content-Disposition' => $disposition . '; filename="' . self::sanitiseName($downloadName) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function path(): string
    {
        return $this->absolutePath;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function downloadName(): string
    {
        return $this->downloadName;
    }

    public function send(): void
    {
        parent::send();
        readfile($this->absolutePath);
    }

    private static function sanitiseName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

        return $clean === null || $clean === '' ? 'document' : $clean;
    }
}
