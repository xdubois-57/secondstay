<?php

declare(strict_types=1);

namespace SecondStay\Core;

/**
 * Session réellement adossée à `$_SESSION`.
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
        $this->data = $session;
        $this->started = true;

        if (!isset($this->data['_id'])) {
            $this->data['_id'] = session_id() === false ? bin2hex(random_bytes(32)) : (string) session_id();
        }
    }

    public function regenerate(): void
    {
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
