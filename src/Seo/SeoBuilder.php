<?php

declare(strict_types=1);

namespace SecondStay\Seo;

use SecondStay\Content\ContentPage;
use SecondStay\Content\ContentService;
use SecondStay\I18n\Locales;
use SecondStay\Settings\SettingsService;

/**
 * SEO multilingue (SPECIFICATIONS.md §9).
 *
 * Chaque page expose title, description, Open Graph, canonical et `hreflang`
 * pour les quatre langues.
 */
final class SeoBuilder
{
    public function __construct(
        private readonly ContentService $content,
        private readonly SettingsService $settings,
    ) {
    }

    public function baseUrl(): string
    {
        return rtrim($this->settings->string('site.public_url'), '/');
    }

    /**
     * @return array<string, string> locale => URL absolue ou relative
     */
    public function alternates(string $path): array
    {
        $alternates = [];
        foreach (Locales::ALL as $locale) {
            $alternates[$locale] = $this->url($locale, $path);
        }

        return $alternates;
    }

    public function url(string $locale, string $path): string
    {
        $normalised = '/' . ltrim($path, '/');
        $normalised = $normalised === '/' ? '/' : rtrim($normalised, '/');

        return $this->baseUrl() . '/' . $locale . ($normalised === '/' ? '/' : $normalised);
    }

    /**
     * Données structurées schema.org pour un meublé de tourisme.
     *
     * @return array<string, mixed>
     */
    public function structuredData(string $locale): array
    {
        $name = $this->settings->string('property.name');
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'LodgingBusiness',
            'name' => $name !== '' ? $name : 'SecondStay',
            'url' => $this->url($locale, '/'),
            'inLanguage' => $locale,
        ];

        $address = array_filter([
            'streetAddress' => trim(
                $this->settings->string('property.address_line1') . ' ' . $this->settings->string('property.address_line2')
            ),
            'postalCode' => $this->settings->string('property.postal_code'),
            'addressLocality' => $this->settings->string('property.city'),
            'addressCountry' => $this->settings->string('property.country'),
        ], static fn (string $value): bool => $value !== '');

        if ($address !== []) {
            $data['address'] = ['@type' => 'PostalAddress'] + $address;
        }

        $latitude = $this->settings->get('property.latitude');
        $longitude = $this->settings->get('property.longitude');
        if (is_numeric($latitude) && is_numeric($longitude)) {
            $data['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        $email = $this->settings->string('property.contact_email');
        if ($email !== '') {
            $data['email'] = $email;
        }
        $phone = $this->settings->string('property.contact_phone');
        if ($phone !== '') {
            $data['telephone'] = $phone;
        }

        $capacity = $this->settings->int('booking.max_guests');
        if ($capacity > 0) {
            $data['maximumAttendeeCapacity'] = $capacity;
        }

        return $data;
    }

    /**
     * Sitemap XML multilingue avec liens alternatifs.
     */
    public function sitemap(): string
    {
        $entries = [['path' => '/', 'priority' => '1.0']];

        foreach ($this->content->visiblePages() as $page) {
            if ($page->kind === \SecondStay\Content\PageKind::Home) {
                continue;
            }
            $entries[] = ['path' => '/' . $page->slug, 'priority' => '0.7'];
        }

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        foreach ($entries as $entry) {
            foreach (Locales::ALL as $locale) {
                $xml->startElement('url');
                $xml->writeElement('loc', $this->url($locale, $entry['path']));
                $xml->writeElement('changefreq', 'weekly');
                $xml->writeElement('priority', $entry['priority']);

                foreach (Locales::ALL as $alternate) {
                    $xml->startElement('xhtml:link');
                    $xml->writeAttribute('rel', 'alternate');
                    $xml->writeAttribute('hreflang', $alternate);
                    $xml->writeAttribute('href', $this->url($alternate, $entry['path']));
                    $xml->endElement();
                }

                $xml->startElement('xhtml:link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', 'x-default');
                $xml->writeAttribute('href', $this->url(Locales::FALLBACK, $entry['path']));
                $xml->endElement();

                $xml->endElement();
            }
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    public function robots(): string
    {
        $lines = [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /login',
            'Disallow: /install',
            'Disallow: /api/',
            // Le planificateur n'a rien à indexer, et une URL déclenchable
            // n'a rien à faire dans un index public même fermée par jeton.
            'Disallow: /tasks/',
            // Les pages ouvertes depuis un QR collé dans le logement sont
            // publiques par nécessité, pas pour être trouvées depuis un
            // moteur de recherche (SPECIFICATIONS.md §47).
            'Disallow: /fr/info/',
            'Disallow: /en/info/',
            'Disallow: /nl/info/',
            'Disallow: /de/info/',
            'Allow: /',
        ];

        $base = $this->baseUrl();
        if ($base !== '') {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, string>
     */
    public function metaForPage(ContentPage $page, string $locale): array
    {
        $translation = $page->translation($locale, Locales::FALLBACK);

        return [
            'title' => $translation === null ? '' : $translation->effectiveMetaTitle(),
            'description' => $translation === null ? '' : $translation->metaDescription,
        ];
    }
}
