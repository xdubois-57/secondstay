<?php

declare(strict_types=1);

namespace SecondStay\I18n;

use SecondStay\Core\Http\Request;

/**
 * Resolution de la langue effective.
 *
 * Ordre (I18N.md section 5) :
 *  1. prefixe d'URL explicite ;
 *  2. preference enregistree du compte ;
 *  3. langue choisie explicitement (cookie fonctionnel) ;
 *  4. Accept-Language ;
 *  5. langue par defaut de l'installation ;
 *  6. `fr`.
 */
final class LocaleResolver
{
    public function __construct(
        private readonly string $installationDefault = Locales::FALLBACK,
        private readonly string $cookieName = 'ss_locale',
    ) {
    }

    /**
     * Extrait un prefixe de locale du chemin.
     *
     * @return array{locale: ?string, path: string}
     */
    public function extractPrefix(string $path): array
    {
        $trimmed = '/' . ltrim($path, '/');
        $segments = explode('/', ltrim($trimmed, '/'));
        $first = $segments[0] ?? '';

        if ($first !== '' && Locales::isSupported($first)) {
            $rest = '/' . implode('/', array_slice($segments, 1));

            return ['locale' => strtolower($first), 'path' => rtrim($rest, '/') === '' ? '/' : rtrim($rest, '/')];
        }

        return ['locale' => null, 'path' => rtrim($trimmed, '/') === '' ? '/' : rtrim($trimmed, '/')];
    }

    public function resolve(Request $request, ?string $urlLocale = null, ?string $accountLocale = null): string
    {
        if ($urlLocale !== null && Locales::isSupported($urlLocale)) {
            return $urlLocale;
        }

        if ($accountLocale !== null) {
            $normalised = Locales::normalise($accountLocale);
            if ($normalised !== null) {
                return $normalised;
            }
        }

        $cookie = $request->cookie($this->cookieName);
        if ($cookie !== null) {
            $normalised = Locales::normalise($cookie);
            if ($normalised !== null) {
                return $normalised;
            }
        }

        $fromHeader = $this->fromAcceptLanguage($request->acceptLanguage());
        if ($fromHeader !== null) {
            return $fromHeader;
        }

        if (Locales::isSupported($this->installationDefault)) {
            return $this->installationDefault;
        }

        return Locales::FALLBACK;
    }

    public function fromAcceptLanguage(string $header): ?string
    {
        if (trim($header) === '') {
            return null;
        }

        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $chunks = explode(';', trim($part));
            $tag = trim($chunks[0]);
            $quality = 1.0;
            foreach (array_slice($chunks, 1) as $chunk) {
                $chunk = trim($chunk);
                if (str_starts_with($chunk, 'q=')) {
                    $quality = (float) substr($chunk, 2);
                }
            }
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $candidates[] = ['tag' => $tag, 'q' => $quality];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);

        foreach ($candidates as $candidate) {
            $normalised = Locales::normalise($candidate['tag']);
            if ($normalised !== null) {
                return $normalised;
            }
        }

        return null;
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }
}
