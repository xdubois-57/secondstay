<?php

declare(strict_types=1);

namespace SecondStay\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Assainissement HTML par liste blanche explicite (SECURITY.md §8).
 *
 * Utilisé pour les contenus éditoriaux riches et, plus tard, pour le HTML des
 * e-mails reçus, qui n'est jamais fiable.
 */
final class HtmlSanitizer
{
    /** @var array<string, list<string>> balise => attributs autorisés */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'blockquote' => [],
        'hr' => [],
        'a' => ['href', 'title', 'rel', 'target'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'figure' => [],
        'figcaption' => [],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => [],
        'td' => [],
        'span' => [],
        'div' => [],
        'small' => [],
        'code' => [],
        'pre' => [],
    ];

    /** @var list<string> éléments supprimés avec leur contenu */
    private const STRIPPED = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'link', 'meta'];

    public function sanitize(string $html): string
    {
        $trimmed = trim($html);
        if ($trimmed === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="secondstay-root">' . $trimmed . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return '';
        }

        $xpath = new DOMXPath($document);
        $root = $document->getElementById('secondstay-root');
        if (!$root instanceof DOMElement) {
            $found = $xpath->query('//div[@id="secondstay-root"]');
            $root = $found !== false && $found->item(0) instanceof DOMElement ? $found->item(0) : null;
        }

        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->clean($root);

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= (string) $document->saveHTML($child);
        }

        return trim($result);
    }

    /**
     * Version texte : utile pour les extraits, les métadonnées et les index.
     */
    public function toText(string $html): string
    {
        $text = strip_tags($this->sanitize($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return trim($collapsed ?? $text);
    }

    private function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::STRIPPED, true)) {
                    $child->parentNode?->removeChild($child);
                    continue;
                }

                if (!array_key_exists($tag, self::ALLOWED)) {
                    // Balise inconnue : on conserve le contenu, pas la balise.
                    $this->clean($child);
                    $this->unwrap($child);
                    continue;
                }

                $this->cleanAttributes($child, self::ALLOWED[$tag]);
                $this->clean($child);
                continue;
            }

            if ($child->nodeType === XML_COMMENT_NODE) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    /**
     * @param list<string> $allowedAttributes
     */
    private function cleanAttributes(DOMElement $element, array $allowedAttributes): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (!in_array($name, $allowedAttributes, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            $value = trim($attribute->nodeValue ?? '');

            if (($name === 'href' || $name === 'src') && !$this->isSafeUrl($value, $name)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        if (strtolower($element->tagName) === 'a' && $element->getAttribute('target') === '_blank') {
            // Une cible externe ne doit jamais donner accès à `window.opener`.
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function isSafeUrl(string $url, string $attribute): bool
    {
        if ($url === '') {
            return false;
        }

        $normalised = strtolower(preg_replace('/\s+/', '', $url) ?? $url);

        foreach (['javascript:', 'vbscript:', 'file:', 'about:'] as $forbidden) {
            if (str_starts_with($normalised, $forbidden)) {
                return false;
            }
        }

        if (str_starts_with($normalised, 'data:')) {
            // Seules les images en data-URI restent acceptables.
            return $attribute === 'src' && str_starts_with($normalised, 'data:image/');
        }

        return true;
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }
}
