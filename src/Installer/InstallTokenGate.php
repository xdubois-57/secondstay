<?php

declare(strict_types=1);

namespace SecondStay\Installer;

use SecondStay\Core\Http\Request;
use SecondStay\Core\Session;

/**
 * Portail par jeton devant l'assistant d'installation.
 *
 * Le jeton n'est présenté qu'une fois : il est vérifié, mémorisé en session,
 * puis retiré de l'URL. Les essais infructueux sont comptés dans la même
 * session et, passé un seuil, l'accès est refusé pendant un temps fixe même
 * si le bon jeton finit par être présenté.
 *
 * Ce verrouillage vaut ce que vaut une session : quelqu'un qui jette son
 * cookie repart à zéro. Ce n'est pas un oubli. Un verrou porté par un état
 * partagé serait, sur une instance non installée, un moyen de bloquer
 * l'installation depuis l'extérieur — le propriétaire, lui, n'aurait alors
 * plus aucun recours. Le compteur n'est pas là pour arrêter une attaque par
 * force brute : 256 bits d'entropie s'en chargent. Il est là pour qu'une
 * telle tentative laisse une trace et coûte quelque chose.
 */
final class InstallTokenGate
{
    public const SESSION_VERIFIED_KEY = '_install_token_verified';

    private const SESSION_FAILURES_KEY = '_install_token_failures';
    private const SESSION_LOCKED_UNTIL_KEY = '_install_token_locked_until';

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900;

    /** @var callable(): int */
    private $now;

    /**
     * @param (callable(): int)|null $now horloge, injectable pour les tests
     */
    public function __construct(
        private readonly InstallToken $token,
        private readonly Session $session,
        ?callable $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /**
     * Trois réponses, et elles ne veulent pas dire la même chose.
     *
     * `Allowed` — rien à présenter, ou déjà présenté : l'assistant s'affiche
     * tel quel. `Accepted` — le jeton vient d'être reconnu, et l'appelant doit
     * rediriger vers la même adresse **sans** le paramètre (§41.3).
     * `Denied` — refus, qu'il soit dû au verrouillage, à l'absence de jeton ou
     * à un jeton faux ; l'extérieur n'a pas à savoir lequel.
     *
     * L'ordre des contrôles est le contrat : le verrouillage est examiné avant
     * le jeton, sans quoi il ne verrouillerait rien ; et une visite **sans**
     * jeton ne consomme pas d'essai, parce que la première ouverture de
     * l'assistant se fait sans, et que la compter épuiserait le budget avant
     * que l'opérateur n'ait rien tenté.
     */
    public function authorise(Request $request): InstallTokenVerdict
    {
        if (!$this->token->isConfigured()) {
            return InstallTokenVerdict::Allowed;
        }

        if ($this->session->get(self::SESSION_VERIFIED_KEY) === true) {
            return InstallTokenVerdict::Allowed;
        }

        if ($this->isLockedOut()) {
            return InstallTokenVerdict::Denied;
        }

        $candidate = (string) $request->query(InstallToken::REQUEST_PARAMETER, '');
        if ($candidate === '') {
            // Ne pas compter comme un essai : la toute première visite de
            // l'assistant se fait sans jeton, et compter cette visite-là
            // consommerait une part du budget avant même que l'opérateur
            // n'ait eu l'occasion de présenter quoi que ce soit.
            return InstallTokenVerdict::Denied;
        }

        if (!$this->token->matches($candidate)) {
            $this->recordFailure();

            return InstallTokenVerdict::Denied;
        }

        $this->session->set(self::SESSION_VERIFIED_KEY, true);
        $this->session->remove(self::SESSION_FAILURES_KEY);
        $this->session->remove(self::SESSION_LOCKED_UNTIL_KEY);

        return InstallTokenVerdict::Accepted;
    }

    /**
     * Adresse à laquelle rediriger après un jeton accepté : la même, sans le
     * paramètre.
     */
    public function cleanUrl(Request $request): string
    {
        $query = $request->query;
        unset($query[InstallToken::REQUEST_PARAMETER]);

        $url = $request->basePath . $request->path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * Le verrou se lève de lui-même : un opérateur qui s'est trompé cinq fois
     * ne reste pas dehors indéfiniment. L'horloge est injectable pour que les
     * tests puissent constater cette levée sans attendre un quart d'heure.
     */
    private function isLockedOut(): bool
    {
        $until = $this->session->int(self::SESSION_LOCKED_UNTIL_KEY);

        return $until !== null && ($this->now)() < $until;
    }

    /**
     * Compte un essai manqué, et verrouille au cinquième.
     *
     * Le compteur repart de zéro **en même temps** que le verrou est posé :
     * sans cela, chaque essai suivant le prolongerait, et une seule session
     * malheureuse condamnerait l'installation bien au-delà du quart d'heure
     * annoncé.
     *
     * Ce compteur n'est pas là pour arrêter une force brute — 256 bits s'en
     * chargent — mais pour qu'une tentative coûte quelque chose.
     */
    private function recordFailure(): void
    {
        $failures = ($this->session->int(self::SESSION_FAILURES_KEY) ?? 0) + 1;
        $this->session->set(self::SESSION_FAILURES_KEY, $failures);

        if ($failures >= self::MAX_ATTEMPTS) {
            $this->session->set(self::SESSION_LOCKED_UNTIL_KEY, ($this->now)() + self::LOCKOUT_SECONDS);
            $this->session->set(self::SESSION_FAILURES_KEY, 0);
        }
    }
}
