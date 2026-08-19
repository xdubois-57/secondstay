<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

/**
 * Marque une valeur à encoder comme chaîne d'octets CBOR (type majeur 2)
 * plutôt que comme texte.
 */
final class CborByteString
{
    public function __construct(public readonly string $value)
    {
    }
}
