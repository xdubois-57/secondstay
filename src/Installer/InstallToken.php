<?php

declare(strict_types=1);

namespace SecondStay\Installer;

/**
 * Jeton protégeant l'assistant d'installation.
 *
 * Sur une instance neuve, l'assistant est ouvert : c'est lui qui crée le
 * premier administrateur, donc il ne peut pas être protégé par une session
 * authentifiée. Sur un hébergement public, cette fenêtre appartient à qui
 * arrive le premier — celui qui charge `/install` avant le propriétaire
 * choisit la base de données, le mot de passe administrateur et devient
 * l'exploitant du site.
 *
 * `bootstrap.php` referme cette fenêtre : il écrit un jeton de 32 octets dans
 * `token.php`, à la racine du site, et n'affiche l'adresse de l'assistant
 * qu'avec ce jeton. Seul quelqu'un qui a un accès FTP au site — donc son
 * propriétaire — peut le lire.
 *
 * Le fichier est lu **comme du texte**, jamais inclus : son contenu vient du
 * disque d'un hébergement dont l'application ne sait rien, et l'exécuter
 * reviendrait à faire tourner ce que le premier fichier déposé à la racine
 * contient.
 *
 * En l'absence de `token.php`, l'assistant reste ouvert. Une installation
 * faite à la main (clone du dépôt, développement, campagne de tests) n'a
 * jamais eu de jeton à présenter, et refuser tout accès dans ce cas
 * transformerait l'absence d'un fichier en verrou définitif.
 */
final class InstallToken
{
    /**
     * Nom du paramètre par lequel le jeton est présenté. Identique à
     * `BOOTSTRAP_TOKEN_PARAMETER` dans `bootstrap/bootstrap.php`.
     */
    public const REQUEST_PARAMETER = 'jeton';

    /**
     * Marqueur du fichier. Identique à `BOOTSTRAP_TOKEN_MARKER`.
     */
    public const MARKER = 'SECONDSTAY-INSTALL-TOKEN';

    public const FILE_NAME = 'token.php';

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Le jeton vit à la racine du projet, là où `bootstrap.php` l'a écrit et
     * où seul un accès FTP — donc le propriétaire du site — peut le lire.
     */
    public static function forRoot(string $projectRoot): self
    {
        return new self(rtrim($projectRoot, '/') . '/' . self::FILE_NAME);
    }

    /** Le chemin du fichier, pour les messages qui disent où le trouver. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Un `token.php` présent mais illisible ou sans marqueur compte comme
     * absent de protection : le contraire enfermerait dehors le propriétaire
     * d'un fichier corrompu, sans aucun gain — un attaquant, lui, ne saurait
     * pas davantage quoi présenter.
     */
    public function isConfigured(): bool
    {
        return $this->read() !== null;
    }

    /**
     * Comparaison en temps constant, et refus de tout ce qui n'a pas la forme
     * attendue.
     *
     * `hash_equals()` plutôt que `===` : la durée de la comparaison ne doit
     * rien dire du préfixe correct. Et un candidat plus court ou hors de
     * l'alphabet n'est pas un jeton faible, c'est autre chose — `read()` ne
     * rend un secret que s'il fait exactement 64 hexadécimaux, donc un tel
     * candidat ne peut jamais correspondre.
     */
    public function matches(string $candidate): bool
    {
        $expected = $this->read();

        return $expected !== null && hash_equals($expected, $candidate);
    }

    /**
     * Le jeton n'a plus d'objet dès qu'un administrateur existe : la fenêtre
     * qu'il protégeait est fermée. Le laisser en place serait un secret de
     * plus à ne pas divulguer, pour rien.
     */
    public function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
            clearstatcache(true, $this->path);
        }
    }

    /**
     * Lit le fichier **comme du texte**, jamais par inclusion.
     *
     * Son contenu vient du disque d'un hébergement dont l'application ne sait
     * rien. L'inclure reviendrait à exécuter ce que le premier fichier déposé
     * à la racine contient — le lire au format attendu ne coûte rien et ne
     * donne aucune prise.
     *
     * `null` signifie « pas de jeton exploitable », que le fichier soit
     * absent, illisible ou sans marqueur : voir `isConfigured()` pour pourquoi
     * ces trois cas se valent.
     */
    private function read(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return null;
        }

        if (preg_match('/' . self::MARKER . '\s*:\s*([0-9a-f]{64})/', $raw, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
