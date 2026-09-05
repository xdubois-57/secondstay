<?php

declare(strict_types=1);

namespace SecondStay\Core;

/**
 * Source de verite unique des chemins qui ne doivent jamais etre servis
 * directement par le serveur web.
 *
 * Le fichier `.htaccess` racine implemente la meme politique pour Apache.
 * Le routeur de developpement (`scripts/router.php`) l'applique pour le
 * serveur PHP integre, et un test verifie que `.htaccess` couvre bien chaque
 * entree declaree ici.
 */
final class PublicPathPolicy
{
    /**
     * Repertoires prives (premier segment du chemin).
     *
     * @var list<string>
     */
    public const BLOCKED_DIRECTORIES = [
        'src',
        'config',
        'vendor',
        'storage',
        'tests',
        'migrations',
        'scripts',
        'bootstrap',
        'translations',
        'templates',
        'node_modules',
        'coverage',
        'build',
        'dist',
        'test-results',
        'playwright-report',
        '.github',
        '.git',
        '.phpunit.cache',
    ];

    /**
     * Fichiers racine prives.
     *
     * @var list<string>
     */
    public const BLOCKED_FILES = [
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'phpunit.xml',
        'phpunit.xml.dist',
        'phpstan.neon',
        'phpstan.neon.dist',
        'playwright.config.js',
        'vitest.config.js',
        'sonar-project.properties',
        'VERSION',
        'LICENSE',
        'MANIFEST.md',
        // Écrit par `bootstrap/bootstrap.php`, jamais servi : il porte le
        // jeton de l'assistant d'installation (SECURITY.md §41). Le
        // `.htaccess` racine l'envoie déjà au contrôleur frontal, qui ne
        // connaît pas cette route ; cette entrée est la défense en
        // profondeur, pour l'hébergement dont la configuration exécuterait
        // un .php posé à la racine avant que la réécriture ne s'applique.
        'token.php',
    ];

    /**
     * Extensions jamais servies telles quelles.
     *
     * @var list<string>
     */
    public const BLOCKED_EXTENSIONS = [
        'sql', 'sqlite', 'db', 'ini', 'log', 'bak', 'swp', 'dist', 'neon',
        'yaml', 'yml', 'sh', 'env', 'pem', 'key', 'crt', 'p12', 'zip', 'tar', 'gz',
        'md',
    ];

    /**
     * Indique si un chemin de requete doit etre refuse par le serveur web.
     */
    public static function isBlocked(string $requestPath): bool
    {
        $path = '/' . ltrim($requestPath, '/');
        $path = self::normalise($path);

        // Un chemin qui tente de remonter est toujours refuse.
        if (str_contains($path, '..')) {
            return true;
        }

        $relative = ltrim($path, '/');
        if ($relative === '') {
            return false;
        }

        // Le prefixe public/ est un alias physique de la racine servie.
        if (str_starts_with($relative, 'public/')) {
            $relative = substr($relative, 7);
        }

        if ($relative === '') {
            return false;
        }

        $segments = explode('/', $relative);
        $first = $segments[0];

        foreach (self::BLOCKED_DIRECTORIES as $directory) {
            if (strcasecmp($first, $directory) === 0) {
                return true;
            }
        }

        foreach (self::BLOCKED_FILES as $file) {
            if (strcasecmp($relative, $file) === 0) {
                return true;
            }
        }

        // Dotfiles, sauf /.well-known/
        foreach ($segments as $segment) {
            if ($segment !== '' && $segment[0] === '.' && $segment !== '.well-known') {
                return true;
            }
        }

        $extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
        if ($extension !== '' && in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return true;
        }

        return false;
    }

    private static function normalise(string $path): string
    {
        $decoded = rawurldecode($path);

        return str_replace('\\', '/', $decoded);
    }
}
