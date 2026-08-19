<?php

declare(strict_types=1);

namespace SecondStay\Core;

/**
 * Enveloppe testable autour de la session PHP.
 *
 * En production elle s'appuie sur `$_SESSION` ; en test elle fonctionne sur un
 * tableau en mémoire, ce qui permet de couvrir CSRF, flash et authentification
 * sans démarrer de session réelle.
 */
class Session
{
    /** @var array<string, mixed> */
    protected array $data = [];

    protected bool $started = false;

    public function start(): void
    {
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    public function int(string $key): ?int
    {
        $value = $this->get($key);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function &reference(): array
    {
        return $this->data;
    }

    public function id(): string
    {
        $id = $this->get('_id');

        return is_string($id) ? $id : '';
    }

    public function regenerate(): void
    {
        $this->set('_id', bin2hex(random_bytes(32)));
    }

    /**
     * Message flash localisé : on stocke une clé de traduction, jamais un
     * texte figé dans une langue (I18N.md §2).
     */
    public function flash(string $type, string $translationKey): void
    {
        /** @var list<array{type: string, key: string}> $messages */
        $messages = is_array($this->get('_flash')) ? $this->get('_flash') : [];
        $messages[] = ['type' => $type, 'key' => $translationKey];
        $this->set('_flash', $messages);
    }

    /**
     * @return list<array{type: string, key: string}>
     */
    public function takeFlashes(): array
    {
        /** @var list<array{type: string, key: string}> $messages */
        $messages = is_array($this->get('_flash')) ? $this->get('_flash') : [];
        $this->remove('_flash');

        return $messages;
    }
}
