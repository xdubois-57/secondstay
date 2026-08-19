<?php

declare(strict_types=1);

namespace SecondStay\Auth\WebAuthn;

use RuntimeException;

/**
 * Décodeur CBOR minimal (RFC 8949), limité à ce que WebAuthn produit :
 * entiers, chaînes d'octets, textes, tableaux, cartes, booléens et null.
 *
 * Écrire ce décodeur évite une dépendance supplémentaire sur un hébergement
 * mutualisé, tout en restant petit et testable.
 */
final class Cbor
{
    /**
     * @return array{value: mixed, offset: int}
     */
    public static function decodeAt(string $data, int $offset = 0): array
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('CBOR tronqué.');
        }

        $initial = ord($data[$offset]);
        $majorType = $initial >> 5;
        $additional = $initial & 0x1F;
        $offset++;

        $value = 0;
        if ($additional < 24) {
            $value = $additional;
        } elseif ($additional === 24) {
            $value = self::readUnsigned($data, $offset, 1);
            $offset += 1;
        } elseif ($additional === 25) {
            $value = self::readUnsigned($data, $offset, 2);
            $offset += 2;
        } elseif ($additional === 26) {
            $value = self::readUnsigned($data, $offset, 4);
            $offset += 4;
        } elseif ($additional === 27) {
            $value = self::readUnsigned($data, $offset, 8);
            $offset += 8;
        } elseif ($additional !== 31) {
            throw new RuntimeException('CBOR : longueur non supportée.');
        }

        switch ($majorType) {
            case 0: // entier non signé
                return ['value' => $value, 'offset' => $offset];

            case 1: // entier négatif
                return ['value' => -1 - $value, 'offset' => $offset];

            case 2: // chaîne d'octets
                self::ensure($data, $offset, $value);
                return ['value' => substr($data, $offset, $value), 'offset' => $offset + $value];

            case 3: // texte
                self::ensure($data, $offset, $value);
                return ['value' => substr($data, $offset, $value), 'offset' => $offset + $value];

            case 4: // tableau
                $items = [];
                for ($index = 0; $index < $value; $index++) {
                    $decoded = self::decodeAt($data, $offset);
                    $items[] = $decoded['value'];
                    $offset = $decoded['offset'];
                }

                return ['value' => $items, 'offset' => $offset];

            case 5: // carte
                $map = [];
                for ($index = 0; $index < $value; $index++) {
                    $key = self::decodeAt($data, $offset);
                    $offset = $key['offset'];
                    $entry = self::decodeAt($data, $offset);
                    $offset = $entry['offset'];

                    if (!is_int($key['value']) && !is_string($key['value'])) {
                        throw new RuntimeException('CBOR : clé de carte non supportée.');
                    }

                    $map[$key['value']] = $entry['value'];
                }

                return ['value' => $map, 'offset' => $offset];

            case 6: // étiquette : la valeur étiquetée est conservée telle quelle
                return self::decodeAt($data, $offset);

            case 7:
                return ['value' => match ($additional) {
                    20 => false,
                    21 => true,
                    22 => null,
                    23 => null,
                    default => throw new RuntimeException('CBOR : valeur simple non supportée.'),
                }, 'offset' => $offset];

            default:
                throw new RuntimeException('CBOR : type majeur inconnu.');
        }
    }

    public static function decode(string $data): mixed
    {
        return self::decodeAt($data, 0)['value'];
    }

    private static function readUnsigned(string $data, int $offset, int $bytes): int
    {
        self::ensure($data, $offset, $bytes);

        $value = 0;
        for ($index = 0; $index < $bytes; $index++) {
            $value = ($value << 8) | ord($data[$offset + $index]);
        }

        return $value;
    }

    private static function ensure(string $data, int $offset, int $length): void
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new RuntimeException('CBOR tronqué.');
        }
    }
}
