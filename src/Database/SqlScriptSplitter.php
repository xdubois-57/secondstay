<?php

declare(strict_types=1);

namespace SecondStay\Database;

/**
 * Découpe un script SQL en instructions exécutables.
 *
 * Le découpage respecte les chaînes, les identifiants échappés et les
 * commentaires : un `;` à l'intérieur d'une chaîne ne coupe pas l'instruction.
 */
final class SqlScriptSplitter
{
    /**
     * @return list<string>
     */
    public static function split(string $script): array
    {
        return self::splitStreaming($script)['statements'];
    }

    /**
     * Découpage incrémental : renvoie les instructions complètes et le reste
     * non terminé, afin de pouvoir restaurer un dump volumineux en flux sans
     * jamais couper au milieu d'une chaîne.
     *
     * @return array{statements: list<string>, remainder: string}
     */
    public static function splitStreaming(string $script): array
    {
        $statements = [];
        $current = '';
        $length = strlen($script);
        $index = 0;

        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;

        while ($index < $length) {
            $char = $script[$index];
            $next = $index + 1 < $length ? $script[$index + 1] : '';

            if (!$inSingle && !$inDouble && !$inBacktick) {
                // Commentaire ligne : -- ou #
                if (($char === '-' && $next === '-') || $char === '#') {
                    $end = strpos($script, "\n", $index);
                    $index = $end === false ? $length : $end + 1;
                    continue;
                }
                // Commentaire bloc
                if ($char === '/' && $next === '*') {
                    $end = strpos($script, '*/', $index + 2);
                    $index = $end === false ? $length : $end + 2;
                    continue;
                }
                if ($char === ';') {
                    $trimmed = trim($current);
                    if ($trimmed !== '') {
                        $statements[] = $trimmed;
                    }
                    $current = '';
                    $index++;
                    continue;
                }
            }

            if ($char === '\\' && ($inSingle || $inDouble)) {
                $current .= $char . $next;
                $index += 2;
                continue;
            }

            if ($char === "'" && !$inDouble && !$inBacktick) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            $current .= $char;
            $index++;
        }

        return ['statements' => $statements, 'remainder' => $current];
    }
}
