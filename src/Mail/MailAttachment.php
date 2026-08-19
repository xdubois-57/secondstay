<?php

declare(strict_types=1);

namespace SecondStay\Mail;

final class MailAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $content,
        public readonly string $mimeType = 'application/octet-stream',
    ) {
    }

    /**
     * Nom de fichier confiné : jamais de séparateur, jamais de guillemet
     * pouvant s'échapper de l'en-tête `Content-Disposition`. Un nom qui ne
     * contient aucun caractère alphanumérique (« ../.. », « ... ») ne porte
     * aucune information et retombe sur un nom neutre.
     */
    public function safeFilename(): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $this->filename);

        if ($clean === null || preg_match('/[A-Za-z0-9]/', $clean) !== 1) {
            return 'document';
        }

        return mb_substr($clean, 0, 120);
    }
}
