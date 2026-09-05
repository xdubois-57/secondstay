<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Auth\Role;

/**
 * Niveau d'accès minimal déclaré par une route.
 *
 * ## Pourquoi cette déclaration existe
 *
 * SecondStay vérifie les rôles **impérativement**, dans le corps de chaque
 * action : `requireOperational()`, `requireAdministrator()`. C'est explicite et
 * lisible, mais cela veut dire qu'une action ajoutée sans l'appel n'est
 * protégée par rien, et que rien ne le signale — il n'existait aucune
 * déclaration à laquelle confronter le comportement réel.
 *
 * Cet enum est cette déclaration. `Tests\Database\AuthorizationMatrixTest`
 * rejoue chaque route avec chaque rôle et compare ce que l'application répond
 * à ce que la route annonce.
 *
 * ## La comparaison va dans les deux sens, et c'est le point
 *
 * Une route dont le comportement est **plus permissif** que sa déclaration est
 * un trou de sécurité. Une route **plus stricte** que sa déclaration est un
 * défaut tout aussi grave, pour une autre raison : elle signifie que la
 * déclaration est fausse, donc que la table des routes ment sur qui accède à
 * quoi, donc que la prochaine personne à la lire se trompera.
 *
 * C'est aussi ce qui rend une annotation oubliée bruyante. `Public` est la
 * valeur par défaut : une route d'administration ajoutée sans y penser est
 * déclarée publique, répond 403 à un visiteur, et la gate refuse — au lieu de
 * laisser passer en silence, ce qu'un défaut permissif aurait fait.
 */
enum Access: string
{
    /** Aucune authentification. */
    case Public = 'public';

    /** N'importe quel compte connecté. */
    case Authenticated = 'authenticated';

    /** Responsable local ou administrateur (`Role::LocalManager`). */
    case Operational = 'operational';

    /** Administrateur seul. */
    case Administrator = 'administrator';

    /**
     * Rôle minimal correspondant, ou `null` quand la route est publique.
     */
    public function minimumRole(): ?Role
    {
        return match ($this) {
            self::Public => null,
            self::Authenticated => Role::Customer,
            self::Operational => Role::LocalManager,
            self::Administrator => Role::Administrator,
        };
    }

    /**
     * Ce rôle satisfait-il l'exigence ? `null` représente un visiteur anonyme.
     */
    public function isSatisfiedBy(?Role $role): bool
    {
        $required = $this->minimumRole();
        if ($required === null) {
            return true;
        }

        return $role !== null && $role->includes($required);
    }
}
