<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SecondStay\Support\HtmlSanitizer;

/**
 * SECURITY.md §8 — le HTML riche passe par une liste blanche explicite.
 */
final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testKeepsAllowedFormatting(): void
    {
        $html = '<p>Une <strong>maison</strong> avec <em>vue</em>.</p><ul><li>Wi-Fi</li><li>Parking</li></ul>';

        self::assertSame($html, $this->sanitizer->sanitize($html));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function dangerousPayloads(): array
    {
        return [
            ['<script>alert(1)</script>', 'alert'],
            ['<p onclick="steal()">Texte</p>', 'onclick'],
            ['<img src="x" onerror="alert(1)">', 'onerror'],
            ['<a href="javascript:alert(1)">lien</a>', 'javascript:'],
            ['<a href="JaVaScRiPt:alert(1)">lien</a>', 'javascript'],
            ['<iframe src="https://evil.test"></iframe>', 'iframe'],
            ['<style>body{display:none}</style>', 'display:none'],
            ['<form action="https://evil.test"><input name="x"></form>', 'evil.test'],
            ['<object data="x.swf"></object>', 'object'],
            ['<a href="vbscript:msgbox(1)">x</a>', 'vbscript'],
        ];
    }

    #[DataProvider('dangerousPayloads')]
    public function testDangerousMarkupIsRemoved(string $payload, string $needle): void
    {
        $result = $this->sanitizer->sanitize($payload);

        self::assertStringNotContainsStringIgnoringCase($needle, $result);
    }

    public function testTextContentOfUnknownTagsIsKept(): void
    {
        self::assertSame('Texte conservé', $this->sanitizer->sanitize('<marquee>Texte conservé</marquee>'));
    }

    public function testScriptContentIsRemovedEntirely(): void
    {
        self::assertSame('<p>Avant</p><p>Après</p>', $this->sanitizer->sanitize(
            '<p>Avant</p><script>var secret = 1;</script><p>Après</p>'
        ));
    }

    public function testExternalLinksGetSafeRelAttribute(): void
    {
        $result = $this->sanitizer->sanitize('<a href="https://example.test" target="_blank">Site</a>');

        self::assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function testImageDataUriIsAllowedButNotOtherDataUris(): void
    {
        $image = $this->sanitizer->sanitize('<img src="data:image/png;base64,AAAA" alt="x">');
        self::assertStringContainsString('data:image/png', $image);

        $link = $this->sanitizer->sanitize('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>');
        self::assertStringNotContainsString('data:text/html', $link);
    }

    public function testCommentsAreRemoved(): void
    {
        self::assertSame('<p>Visible</p>', $this->sanitizer->sanitize('<p>Visible</p><!-- caché -->'));
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', $this->sanitizer->sanitize(''));
        self::assertSame('', $this->sanitizer->sanitize('   '));
    }

    public function testUtf8IsPreserved(): void
    {
        $html = '<p>Séjour à Saint-Étienne — été 2026, één woning, Größe</p>';

        self::assertSame($html, $this->sanitizer->sanitize($html));
    }

    public function testToTextStripsMarkupAndCollapsesWhitespace(): void
    {
        self::assertSame(
            'Une maison avec vue.',
            $this->sanitizer->toText("<p>Une <strong>maison</strong>\n   avec <em>vue</em>.</p>")
        );
    }

    public function testNestedDangerousMarkupIsRemoved(): void
    {
        $result = $this->sanitizer->sanitize('<div><p>Bien<script>alert(1)</script></p></div>');

        self::assertStringNotContainsString('alert', $result);
        self::assertStringContainsString('Bien', $result);
    }

    public function testTablesAreSupported(): void
    {
        $html = '<table><thead><tr><th>Période</th></tr></thead><tbody><tr><td>Juillet</td></tr></tbody></table>';

        self::assertSame($html, $this->sanitizer->sanitize($html));
    }
}
