<?php

declare(strict_types=1);

namespace SecondStay\LocalContent;

/**
 * Réduit une page HTML au texte qu'un lecteur y verrait.
 *
 * Deux raisons de ne pas envoyer le HTML brut au modèle : le bruit — scripts,
 * styles, navigation — coûte des jetons pour rien, et surtout les balises
 * offrent mille façons de cacher une instruction. Ce qui sort d'ici est du
 * texte, borné, sans balise.
 */
final class PageExtractor
{
    /** Longueur maximale retenue par page, en caractères. */
    public const MAX_LENGTH = 12000;

    /** Éléments dont le contenu n'a rien à faire dans le texte. */
    private const DROPPED = ['script', 'style', 'noscript', 'template', 'svg', 'iframe'];

    public function extract(string $html, int $maxLength = self::MAX_LENGTH): string
    {
        foreach (self::DROPPED as $tag) {
            $html = (string) preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', ' ', $html);
        }

        // Les commentaires peuvent contenir tout et n'importe quoi.
        $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);

        // Les fins de bloc deviennent des retours à la ligne : une liste de
        // dates reste alors une ligne par date.
        $html = (string) preg_replace('#</(p|div|li|tr|h[1-6]|section|article)>#i', "\n", $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Espaces normalisés, lignes vides supprimées : le texte reste lisible
        // et le nombre de jetons reste raisonnable.
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $joined = implode("\n", $lines);

        return mb_substr($joined, 0, max(100, $maxLength));
    }
}
