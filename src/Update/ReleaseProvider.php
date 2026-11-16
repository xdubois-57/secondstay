<?php

declare(strict_types=1);

namespace SecondStay\Update;

/**
 * Frontière externe du système de mise à jour (ARCHITECTURE.md §3).
 */
interface ReleaseProvider
{
    /**
     * Dernière release installable, ou null si aucune n'est disponible.
     */
    public function latest(bool $allowPrerelease = false): ?ReleaseInfo;

    /**
     * Dernière construction de développement publiée par la CI (étiquette
     * `dev-latest`), ou null si aucune n'est disponible.
     */
    public function devBuild(): ?ReleaseInfo;

    /**
     * Télécharge l'asset attendu dans `$destination`.
     *
     * @return int taille téléchargée en octets
     */
    public function download(ReleaseInfo $release, string $destination): int;
}
