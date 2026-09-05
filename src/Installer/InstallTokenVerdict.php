<?php

declare(strict_types=1);

namespace SecondStay\Installer;

/**
 * Décision du portail par jeton devant l'assistant d'installation.
 */
enum InstallTokenVerdict
{
    /** Accès autorisé : pas de jeton configuré, ou déjà présenté. */
    case Allowed;

    /**
     * Jeton correct, présenté à l'instant. Le contrôleur appelant doit
     * rediriger vers la même adresse **sans** le paramètre : un jeton dans
     * l'URL finit dans l'historique du navigateur, dans le `Referer` de
     * chaque ressource externe et dans les journaux de l'hébergeur.
     */
    case Accepted;

    /** Jeton absent, faux, ou trop d'essais. */
    case Denied;
}
