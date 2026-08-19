<?php

declare(strict_types=1);

namespace SecondStay\Installer;

/**
 * État d'installation d'une instance.
 *
 * La distinction entre « jamais installée » et « installée mais indisponible »
 * est une exigence de sécurité : une panne de base de données ne doit jamais
 * rouvrir l'assistant d'installation d'une instance déjà déployée
 * (SECURITY.md §5).
 */
enum InstallationStatus: string
{
    /** Aucune configuration locale : déploiement neuf. */
    case NotInstalled = 'not_installed';

    /** Configuration locale présente et instance pleinement fonctionnelle. */
    case Installed = 'installed';

    /**
     * Configuration locale présente mais base injoignable, schéma absent ou
     * aucun administrateur actif : l'instance est indisponible.
     */
    case Unavailable = 'unavailable';

    public function allowsInstaller(): bool
    {
        return $this === self::NotInstalled;
    }

    public function isOperational(): bool
    {
        return $this === self::Installed;
    }
}
