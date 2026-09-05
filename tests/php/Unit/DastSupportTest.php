<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// `scripts/dast-support.php` répartit sur `$argv` dès qu'il est chargé. La
// constante neutralise cette répartition et rend le fichier chargeable, comme
// `BOOTSTRAP_TEST` pour l'installeur.
if (!defined('DAST_SUPPORT_TEST')) {
    define('DAST_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/dast-support.php';

/**
 * Les fonctions de jugement du harnais de scan dynamique.
 *
 * Elles décident si le câblage HTTPS est vivant avant qu'une campagne ne
 * démarre. Elles n'avaient aucun test, et ce fichier a déjà rendu **deux**
 * verdicts faux pour la même raison — un garde-fou qui regarde à côté de ce
 * qu'il surveille :
 *
 * 1. la preuve du cookie acceptait n'importe quel `Set-Cookie` contenant
 *    « secure », et la préférence de langue en pose un. Pendant ce temps le
 *    cookie de session n'était jamais `Secure`, sur du code que cette preuve
 *    déclarait sain ;
 * 2. la preuve HSTS cherchait `max-age` comme **sous-chaîne**, si bien que
 *    `not-max-age=31536000` passait — un en-tête qu'un navigateur ignore.
 *
 * Les deux rendaient le silence rassurant. D'où ces tests.
 */
final class DastSupportTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public static function hstsHeaders(): array
    {
        return [
            ['Strict-Transport-Security: max-age=31536000; includeSubDomains', true, 'la politique nominale'],
            ['strict-transport-security: max-age=31536000', true, 'le nom d’en-tête est insensible à la casse'],
            ['Strict-Transport-Security: MAX-AGE="31536000"', true, 'RFC 6797 : nom insensible à la casse, valeur citable'],
            ['Strict-Transport-Security: includeSubDomains; max-age=600', true, 'l’ordre des directives est libre'],
            ['Strict-Transport-Security: max-age = 600 ; preload', true, 'les espaces autour du signe égal sont tolérés'],

            // `max-age=0` demande au navigateur d'OUBLIER la politique.
            // L'accepter comme preuve validerait le contraire de ce qu'on
            // vérifie.
            ['Strict-Transport-Security: max-age=0', false, 'max-age=0 désactive HSTS'],

            // Le constat de revue : huit caractères présents ne font pas une
            // directive. Un navigateur ignore une directive inconnue.
            ['Strict-Transport-Security: not-max-age=31536000', false, 'directive inconnue contenant « max-age »'],
            ['Strict-Transport-Security: xmax-age=31536000; preload', false, 'préfixe collé au nom de directive'],
            ['Strict-Transport-Security: max-age-extra=31536000', false, 'suffixe collé au nom de directive'],

            ['Strict-Transport-Security: includeSubDomains', false, 'aucune directive max-age'],
            ['Strict-Transport-Security:', false, 'en-tête vide'],
            ['Strict-Transport-Security: max-age=', false, 'valeur absente'],
            ['Strict-Transport-Security: max-age=abc', false, 'valeur illisible'],

            // Un autre en-tête qui contiendrait la même chaîne ne doit jamais
            // être lu comme une politique HSTS.
            ['Cache-Control: max-age=31536000', false, 'un autre en-tête portant max-age'],
            ['X-Strict-Transport-Security: max-age=31536000', false, 'un en-tête au nom voisin'],
        ];
    }

    #[DataProvider('hstsHeaders')]
    public function testHstsIsJudgedOnAnEffectiveDirective(string $header, bool $effective, string $why): void
    {
        self::assertSame($effective, dastHstsIsEffective($header), $why);
    }

    /**
     * La preuve du cookie regarde le cookie **nommé**, et non n'importe quel
     * `Set-Cookie` contenant « secure ». C'est la première des deux erreurs
     * décrites en tête de cette classe.
     */
    public function testTheSessionCookieIsRecognisedByItsName(): void
    {
        $languePreference = 'Set-Cookie: ss_locale=fr; Path=/; Secure; SameSite=Lax';

        // Le cookie de préférence de langue porte `Secure` — et ne prouve rien
        // du cookie de session.
        self::assertFalse(dastSessionCookieIsSecure([$languePreference], 'secondstay_session'));

        self::assertTrue(dastSessionCookieIsSecure(
            [$languePreference, 'Set-Cookie: secondstay_session=abc; Path=/; HttpOnly; Secure; SameSite=Lax'],
            'secondstay_session'
        ));

        // Posé, mais sans le drapeau : c'est un vrai défaut de l'application,
        // et il doit se distinguer d'un cookie jamais posé.
        self::assertFalse(dastSessionCookieIsSecure(
            ['Set-Cookie: secondstay_session=abc; Path=/; HttpOnly; SameSite=Lax'],
            'secondstay_session'
        ));
    }

    /**
     * Un préfixe de nom ne doit pas suffire : `secondstay_session_other` n'est
     * pas le cookie de session.
     */
    public function testACookieWhoseNameMerelyStartsTheSameIsNotTheSessionCookie(): void
    {
        self::assertFalse(dastSessionCookieIsSecure(
            ['Set-Cookie: secondstay_session_other=abc; Path=/; Secure'],
            'secondstay_session'
        ));
    }
}
