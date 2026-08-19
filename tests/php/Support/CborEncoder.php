<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use RuntimeException;

/**
 * Encodeur CBOR minimal, réservé aux tests : la production ne fait que décoder.
 */
final class CborEncoder
{
    public static function encode(mixed $value): string
    {
        if ($value instanceof CborByteString) {
            return self::head(2, strlen($value->value)) . $value->value;
        }

        if (is_int($value)) {
            return $value >= 0
                ? self::head(0, $value)
                : self::head(1, -1 - $value);
        }

        if (is_string($value)) {
            return self::head(3, strlen($value)) . $value;
        }

        if (is_bool($value)) {
            return chr(0xE0 | ($value ? 21 : 20));
        }

        if ($value === null) {
            return chr(0xE0 | 22);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $encoded = self::head(4, count($value));
                foreach ($value as $item) {
                    $encoded .= self::encode($item);
                }

                return $encoded;
            }

            $encoded = self::head(5, count($value));
            foreach ($value as $key => $item) {
                $encoded .= self::encode(is_int($key) ? $key : (string) $key);
                $encoded .= self::encode($item);
            }

            return $encoded;
        }

        throw new RuntimeException('Type CBOR non supporté dans les tests.');
    }

    private static function head(int $majorType, int $value): string
    {
        $prefix = $majorType << 5;

        if ($value < 24) {
            return chr($prefix | $value);
        }
        if ($value < 0x100) {
            return chr($prefix | 24) . chr($value);
        }
        if ($value < 0x10000) {
            return chr($prefix | 25) . pack('n', $value);
        }
        if ($value < 0x100000000) {
            return chr($prefix | 26) . pack('N', $value);
        }

        return chr($prefix | 27) . pack('J', $value);
    }
}
