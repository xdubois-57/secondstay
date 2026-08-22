<?php

declare(strict_types=1);

namespace SecondStay\Scheduler;

/**
 * Résultat d'une exécution de tâche.
 *
 * « Ignorée » n'est pas « réussie » : une relève IMAP qui ne tourne pas parce
 * que la boîte n'est pas configurée doit se lire comme telle sur l'écran
 * d'exploitation, sinon le propriétaire croit relever son courrier alors que
 * rien ne le relève.
 */
final class TaskOutcome
{
    public const OK = 'ok';
    public const SKIPPED = 'skipped';
    public const ERROR = 'error';

    /**
     * @param string $detail clé de traduction, jamais un message brut : le
     *                       détail est rendu dans les quatre langues, et un
     *                       message de fournisseur peut porter un hôte ou un
     *                       chemin
     * @param int    $count  quantité traitée, paramètre de la traduction
     */
    private function __construct(
        public readonly string $status,
        public readonly string $detail,
        public readonly int $count,
    ) {
    }

    public static function ok(string $detail = 'scheduler.detail.nothing', int $count = 0): self
    {
        return new self(self::OK, $detail, max(0, $count));
    }

    public static function skipped(string $detail = 'scheduler.detail.disabled'): self
    {
        return new self(self::SKIPPED, $detail, 0);
    }

    public static function error(string $detail, int $count = 0): self
    {
        return new self(self::ERROR, $detail, max(0, $count));
    }

    public function isError(): bool
    {
        return $this->status === self::ERROR;
    }
}
