<?php

declare(strict_types=1);

namespace SecondStay\Update;

/**
 * Les deux formes de version qu'une installation peut porter dans `VERSION`.
 *
 * - une **release** : `MAJOR.MINOR.PATCH`, publiée sous l'étiquette
 *   `vMAJOR.MINOR.PATCH` ;
 * - une **construction de développement** : `MAJOR.MINOR.PATCH-dev.<sha7>`,
 *   produite par `.github/workflows/dev-build.yml` à chaque poussée sur la
 *   branche suivie. `MAJOR.MINOR.PATCH` y est la dernière release dont la
 *   branche descend, `<sha7>` le commit construit.
 *
 * Comparer ces formes avec `version_compare()` serait faux : PHP classe
 * `1.2.3-dev.abc` **avant** `1.2.3`, alors que ce commit est postérieur à la
 * release. Une installation de développement qui repasse sur le canal stable
 * se verrait proposer une version plus ancienne que celle qu'elle exécute.
 */
final class UpdateVersion
{
    private const RELEASE = '/^(\d+\.\d+\.\d+)\z/';
    private const DEV = '/^(\d+\.\d+\.\d+)-dev\.([0-9a-f]{7,40})\z/';

    public static function isRelease(string $version): bool
    {
        return preg_match(self::RELEASE, $version) === 1;
    }

    public static function isDev(string $version): bool
    {
        return preg_match(self::DEV, $version) === 1;
    }

    public static function isValid(string $version): bool
    {
        return self::isRelease($version) || self::isDev($version);
    }

    /**
     * Commit d'une construction de développement, ou null pour une release.
     */
    public static function commit(string $version): ?string
    {
        return preg_match(self::DEV, $version, $matches) === 1 ? $matches[2] : null;
    }

    /**
     * Release dont une version descend : elle-même pour une release, la base
     * pour une construction de développement, `0.0.0` pour l'inconnu.
     */
    public static function base(string $version): string
    {
        if (preg_match(self::RELEASE, $version, $matches) === 1 || preg_match(self::DEV, $version, $matches) === 1) {
            return $matches[1];
        }

        return '0.0.0';
    }

    /**
     * Une release candidate est-elle plus récente que ce qui est installé ?
     *
     * Une construction de développement descend de sa base et contient des
     * commits postérieurs : seule une release **strictement supérieure** à
     * cette base est donc plus récente. La release égale à la base est plus
     * ancienne que ce qui tourne.
     */
    public static function isNewerRelease(string $candidate, string $installed): bool
    {
        if (!self::isRelease($candidate)) {
            return false;
        }

        return version_compare($candidate, self::base($installed), '>');
    }
}
