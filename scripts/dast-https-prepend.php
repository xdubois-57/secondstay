<?php

declare(strict_types=1);

/**
 * `auto_prepend_file` du serveur de test servi derrière le terminateur TLS, et
 * de rien d'autre. Chargé par `scripts/dev-server.sh` quand
 * `SECONDSTAY_PHP_PREPEND` le désigne — exactement comme
 * `scripts/coverage-bootstrap.php` est chargé pour une campagne instrumentée.
 *
 * CE QU'IL FAIT, ET POURQUOI CE N'EST PAS DANS L'APPLICATION
 * ---------------------------------------------------------------------------
 * `scripts/dast-tls-proxy.php` termine TLS devant `php -S` et pose
 * `X-Forwarded-Proto: https`. L'application, elle, doit pouvoir décider en
 * production sur la seule foi de `$_SERVER['HTTPS']`, que lui donne un vrai
 * hébergement derrière Apache. C'est ici, dans le harnais, que l'en-tête est
 * traduit — sur un serveur qui n'est joignable que depuis le terminateur TLS
 * de la campagne, en boucle locale.
 *
 * Sans cette traduction, l'instance servie en HTTPS ne se croirait pas en
 * HTTPS : pas d'en-tête HSTS, pas de cookie de session `Secure`. Un scan
 * rapporterait alors deux constats **faux**, à propos de code **correct**. La
 * tentation serait de faire taire les deux règles par un filtre d'alertes, et
 * c'est précisément ainsi qu'un rapport cesse d'être lu : deux règles muettes
 * pour un défaut de harnais sont deux règles que personne ne regarde le jour
 * où l'une d'elles se déclenche pour de bon. On répare donc le harnais.
 *
 * CECI EST DU CODE DE TEST. Il ne part dans aucune release — `scripts/` est
 * exclu de l'archive — et aucun déploiement ne l'exécute.
 */

if (
    PHP_SAPI === 'cli-server'
    && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
) {
    // La valeur que posent Apache et nginx, et celle que `Request::isSecure()`
    // examine en premier.
    $_SERVER['HTTPS'] = 'on';

    // Gardé cohérent pour que tout ce qui dérive une URL du schéma soit
    // d'accord avec lui. Le terminateur préserve `Host` : le port qu'il porte
    // est déjà celui de la façade HTTPS.
    $_SERVER['REQUEST_SCHEME'] = 'https';
}
