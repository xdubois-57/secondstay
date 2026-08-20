<?php

declare(strict_types=1);

namespace SecondStay\Document;

/**
 * Provenance d'un document : elle détermine à qui l'on peut en attribuer le
 * contenu, et si l'application peut le régénérer.
 */
enum DocumentSource: string
{
    case Generated = 'generated';
    case Upload = 'upload';
    case Mail = 'mail';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::Upload;
    }

    public function labelKey(): string
    {
        return 'document.source.' . $this->value;
    }
}
