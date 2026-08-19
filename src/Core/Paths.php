<?php

declare(strict_types=1);

namespace SecondStay\Core;

use RuntimeException;

final class Paths
{
    public function __construct(
        private readonly string $root,
        private readonly string $storage,
    ) {
    }

    public function root(string $relative = ''): string
    {
        return $this->join($this->root, $relative);
    }

    public function storage(string $relative = ''): string
    {
        return $this->join($this->storage, $relative);
    }

    public function templates(): string
    {
        return $this->root . '/templates';
    }

    public function translations(): string
    {
        return $this->root . '/translations';
    }

    public function migrations(): string
    {
        return $this->root . '/migrations';
    }

    public function public(string $relative = ''): string
    {
        return $this->join($this->root . '/public', $relative);
    }

    public function ensureStorageDirectories(): void
    {
        foreach ([
            '', 'cache', 'cache/twig', 'logs', 'temp', 'media', 'media/thumbs',
            'documents', 'inspections', 'mail-attachments', 'backups',
        ] as $directory) {
            $path = $this->storage($directory);
            if (!is_dir($path) && !mkdir($path, 0o750, true) && !is_dir($path)) {
                throw new RuntimeException('Impossible de créer le répertoire de stockage : ' . $path);
            }
        }
    }

    private function join(string $base, string $relative): string
    {
        $relative = trim($relative, '/');

        return $relative === '' ? $base : $base . '/' . $relative;
    }
}
