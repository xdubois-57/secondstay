<?php

declare(strict_types=1);

namespace SecondStay\Push;

/**
 * Notification affichée par le service worker.
 *
 * La charge utile reste volontairement minimale : un titre, un texte court et
 * un chemin applicatif. Aucune donnée sensible ne transite par le service de
 * push, même chiffrée.
 */
final class PushMessage
{
    public const MAX_TITLE = 120;
    public const MAX_BODY = 300;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $path = '/',
        public readonly string $tag = 'secondstay',
        public readonly string $locale = 'fr',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'title' => mb_substr($this->title, 0, self::MAX_TITLE),
            'body' => mb_substr($this->body, 0, self::MAX_BODY),
            'path' => $this->path,
            'tag' => $this->tag,
            'locale' => $this->locale,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
