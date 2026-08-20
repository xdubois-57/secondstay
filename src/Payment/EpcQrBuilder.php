<?php

declare(strict_types=1);

namespace SecondStay\Payment;

use InvalidArgumentException;
use SecondStay\Support\QrCode;

/**
 * Construit le message « EPC069-12 » que lisent les applications bancaires
 * européennes pour préremplir un virement SEPA.
 *
 * L'intérêt pratique : le client scanne, sa banque affiche IBAN, montant et
 * référence déjà remplis, et la référence permet de rapprocher le virement
 * de la réservation sans saisie manuelle.
 *
 * Le format est strictement contraint par la spécification : douze lignes
 * séparées par un saut de ligne, encodage UTF-8, 331 octets au maximum.
 */
final class EpcQrBuilder
{
    public const SERVICE_TAG = 'BCD';
    public const VERSION = '002';
    public const ENCODING_UTF8 = '1';
    public const IDENTIFICATION = 'SCT';

    /** Longueur maximale imposée par la spécification EPC069-12. */
    public const MAX_LENGTH = 331;

    /**
     * @param string $beneficiaryName nom du bénéficiaire, 70 caractères au maximum
     * @param string $iban            IBAN du bénéficiaire
     * @param int    $amountCents     montant en centimes, strictement positif
     * @param string $reference       référence libre affichée au payeur, 140 caractères au maximum
     * @param string $currency        devise ISO 4217, EUR par défaut
     * @param string $bic             BIC facultatif, requis hors zone SEPA par certaines banques
     */
    public static function payload(
        string $beneficiaryName,
        string $iban,
        int $amountCents,
        string $reference,
        string $currency = 'EUR',
        string $bic = ''
    ): string {
        $name = self::sanitise($beneficiaryName, 70);
        if ($name === '') {
            throw new InvalidArgumentException('epc.error.beneficiary_required');
        }

        $iban = self::normaliseIban($iban);
        if (!self::isValidIban($iban)) {
            throw new InvalidArgumentException('epc.error.iban_invalid');
        }

        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('epc.error.currency_invalid');
        }

        // La spécification borne le montant à 0,01 – 999 999 999,99.
        if ($amountCents < 1 || $amountCents > 99_999_999_999) {
            throw new InvalidArgumentException('epc.error.amount_out_of_range');
        }

        $bic = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $bic) ?? '');
        if ($bic !== '' && preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) !== 1) {
            throw new InvalidArgumentException('epc.error.bic_invalid');
        }

        // Ligne 10 « remittance information (structured) » et ligne 11
        // « unstructured » s'excluent : SecondStay utilise la seconde, la
        // seule qui accepte une référence lisible par un humain.
        $lines = [
            self::SERVICE_TAG,
            self::VERSION,
            self::ENCODING_UTF8,
            self::IDENTIFICATION,
            $bic,
            $name,
            $iban,
            sprintf('%s%s', $currency, self::amount($amountCents)),
            '',
            '',
            '',
            '',
        ];

        return self::assemble($lines, $name, self::sanitise($reference, 140));
    }

    /**
     * Rendu SVG directement intégrable dans une page ou un e-mail.
     */
    public static function svg(
        string $beneficiaryName,
        string $iban,
        int $amountCents,
        string $reference,
        string $currency = 'EUR',
        string $bic = ''
    ): string {
        return QrCode::toSvg(
            self::payload($beneficiaryName, $iban, $amountCents, $reference, $currency, $bic)
        );
    }

    public static function normaliseIban(string $iban): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? '');
    }

    /**
     * Affichage groupé par quatre, comme sur un relevé bancaire.
     */
    public static function formatIban(string $iban): string
    {
        return trim(chunk_split(self::normaliseIban($iban), 4, ' '));
    }

    /**
     * Contrôle ISO 7064 MOD 97-10, celui-là même qu'applique la banque.
     */
    public static function isValidIban(string $iban): bool
    {
        $iban = self::normaliseIban($iban);

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban) !== 1) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        $digits = '';
        foreach (str_split($rearranged) as $character) {
            $digits .= ctype_digit($character)
                ? $character
                : (string) (ord($character) - ord('A') + 10);
        }

        // Le nombre dépasse largement un entier : on réduit par tranches.
        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ((string) $remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }

    /**
     * Assemble les douze lignes en respectant la limite de 331 octets.
     *
     * Les bornes de la spécification sont exprimées en caractères pour le
     * nom et la référence, mais en octets pour le message complet : un nom
     * accentué peut donc rester dans ses 70 caractères et faire déborder le
     * tout. Plutôt que de refuser un virement pour cette raison, on rogne le
     * champ le plus long — sans jamais toucher à l'IBAN ni au montant, qui
     * seuls conditionnent l'exécution du virement.
     *
     * @param list<string> $lines
     */
    private static function assemble(array $lines, string $name, string $reference): string
    {
        $lines[5] = $name;
        $lines[10] = $reference;
        $payload = implode("\n", $lines);

        while (strlen($payload) > self::MAX_LENGTH) {
            if (strlen($name) >= strlen($reference) && mb_strlen($name, 'UTF-8') > 1) {
                $name = rtrim(mb_substr($name, 0, mb_strlen($name, 'UTF-8') - 1, 'UTF-8'));
            } elseif (mb_strlen($reference, 'UTF-8') > 0) {
                $reference = rtrim(mb_substr($reference, 0, mb_strlen($reference, 'UTF-8') - 1, 'UTF-8'));
            } else {
                throw new InvalidArgumentException('epc.error.payload_too_long');
            }

            $lines[5] = $name;
            $lines[10] = $reference;
            $payload = implode("\n", $lines);
        }

        return $payload;
    }

    /**
     * Montant EPC : point décimal, deux décimales, sans séparateur de
     * milliers ni espace.
     */
    private static function amount(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * Une seule ligne, sans caractère de contrôle : un saut de ligne inséré
     * ici décalerait tous les champs suivants.
     */
    private static function sanitise(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value) ?? '';
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (mb_strlen($value, 'UTF-8') > $limit) {
            $value = rtrim(mb_substr($value, 0, $limit, 'UTF-8'));
        }

        return $value;
    }
}
