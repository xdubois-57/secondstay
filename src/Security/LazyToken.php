<?php

declare(strict_types=1);

namespace SecondStay\Security;

use Stringable;

/**
 * Jeton CSRF évalué à l'affichage.
 *
 * Émettre un jeton ouvre une session, donc pose un cookie. Une page sans
 * formulaire — la page hors ligne mise en cache par le service worker, une
 * page publique consultée par un robot — n'a besoin ni de l'un ni de l'autre :
 * le jeton n'est calculé que si un gabarit l'écrit réellement.
 */
final class LazyToken implements Stringable
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function __toString(): string
    {
        return $this->csrf->token();
    }
}
