<?php

declare(strict_types=1);

namespace SecondStay\Core;

/**
 * Session réellement adossée à `$_SESSION`.
 *
 * L'ouverture est **paresseuse** : une requête qui n'écrit rien (sitemap,
 * manifeste, service worker, icône, page hors ligne anonyme) ne reçoit aucun
 * cookie de session. Cela évite de créer une session par passage de robot,
 * garde ces réponses réellement cachables, et empêche une requête annexe —
 * celle d'un service worker, par exemple — de remplacer la session de
 * l'onglet ouvert.
 */
final class PhpSession extends Session
{
    public function __construct(
        private readonly string $name = 'secondstay_session',
        private readonly int $lifetimeMinutes = 120,
        private readonly bool $secure = false,
        private readonly string $path = '/',
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        // Sans cookie, rien à reprendre : on n'ouvre une session que si la
        // requête finit par écrire quelque chose.
        if (session_status() === PHP_SESSION_NONE && !isset($_COOKIE[$this->name])) {
            return;
        }

        $this->open();
    }

    /**
     * Ouvre réellement la session PHP (et émet donc le cookie).
     */
    private function open(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($this->name);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $this->path,
                'secure' => $this->secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.gc_maxlifetime', (string) ($this->lifetimeMinutes * 60));
            session_start();
        }

        /** @var array<string, mixed> $session */
        $session = $_SESSION;
        // Ce qui a été écrit avant l'ouverture réelle est conservé.
        $this->data = $this->data === [] ? $session : $this->data + $session;

        if (!isset($this->data['_id'])) {
            $this->data['_id'] = session_id() === false ? bin2hex(random_bytes(32)) : (string) session_id();
        }
    }

    /**
     * Toute écriture ouvre la session : c'est le seul moment où un cookie est
     * réellement nécessaire.
     */
    public function set(string $key, mixed $value): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $this->open();
        }

        parent::set($key, $value);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $this->open();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $this->data['_id'] = (string) session_id();

            return;
        }

        parent::regenerate();
    }

    public function persist(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = $this->data;
            session_write_close();
        }
    }

    public function destroy(): void
    {
        $this->data = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }
}
