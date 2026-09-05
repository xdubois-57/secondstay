<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Http\CurlHttpFetcher;

/**
 * La restriction de protocoles doit survivre au changement d'hébergement.
 *
 * `CURLOPT_PROTOCOLS_STR` n'existe que si PHP a été construit avec
 * libcurl ≥ 7.85. Nommée directement, elle faisait échouer **toute** requête
 * sortante sur « Undefined constant » là où elle manque — pas seulement la
 * restriction. Le défaut ne se voyait pas : l'exécuteur d'intégration
 * continue la connaît, et une seule version de PHP y était jouée.
 */
final class CurlHttpFetcherTest extends TestCase
{
    public function testTheProtocolRestrictionIsExpressedInAFormTheHostUnderstands(): void
    {
        $options = CurlHttpFetcher::protocolRestrictionOptions();

        self::assertCount(2, $options, 'La requête et les redirections sont restreintes toutes les deux.');

        foreach ($options as $option => $value) {
            self::assertGreaterThan(0, $option, 'Une option cURL inconnue vaudrait 0 et ne restreindrait rien.');

            if (is_string($value)) {
                self::assertSame('http,https', $value);
                continue;
            }

            self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $value);
        }
    }

    /**
     * Le vrai garde-fou : la forme récente n'est jamais nommée en dur.
     *
     * Ce contrôle tient sur n'importe quel hôte, y compris celui — le nôtre —
     * où les constantes existent et où le défaut était donc invisible.
     */
    public function testTheRecentCurlConstantsAreNeverNamedDirectly(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Http/CurlHttpFetcher.php');
        self::assertIsString($source);

        // Analyse lexicale plutôt qu'expression régulière : `defined('…')` et
        // les commentaires citent forcément ces noms, et un motif textuel ne
        // saurait pas les distinguer d'une vraie référence.
        $named = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_STRING && str_starts_with($token[1], 'CURLOPT_')) {
                $named[] = $token[1];
            }
        }

        foreach (['CURLOPT_PROTOCOLS_STR', 'CURLOPT_REDIR_PROTOCOLS_STR'] as $name) {
            self::assertNotContains(
                $name,
                $named,
                $name . ' est nommée directement : elle manque sur les hôtes liés à une libcurl plus ancienne.'
            );
        }

        // Et le contrôle ne prouverait rien s'il ne voyait aucune constante :
        // les options historiques, elles, doivent bien être nommées.
        self::assertContains('CURLOPT_PROTOCOLS', $named);
    }
}
