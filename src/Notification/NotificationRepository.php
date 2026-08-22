<?php

declare(strict_types=1);

namespace SecondStay\Notification;

use SecondStay\Database\Database;

/**
 * Journal des notifications : une ligne par canal et par tentative
 * (ARCHITECTURE.md §14). Le contenu du message n'y figure pas.
 */
final class NotificationRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function record(
        NotificationEvent $event,
        NotificationChannel $channel,
        string $status,
        ?int $userId,
        string $locale,
        string $subject = '',
        string $reference = '',
        string $error = '',
        string $correlationId = '',
    ): int {
        return $this->database->insert('notification', [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'event' => $event->value,
            'channel' => $channel->value,
            'status' => $status,
            'user_id' => $userId,
            'locale' => $locale,
            'subject' => mb_substr($subject, 0, 255),
            'reference' => mb_substr($reference, 0, 190),
            'error' => mb_substr($error, 0, 255),
            'correlation_id' => $correlationId,
        ]);
    }

    /** Statut d'une tentative qui n'a rien délivré. */
    public const FAILED = 'failed';

    /**
     * Un envoi a-t-il déjà été **traité** pour cet événement et cette
     * référence ?
     *
     * Les tâches périodiques repassent tous les jours sur les mêmes séjours :
     * sans cette question, un rappel de séjour partirait chaque nuit jusqu'à
     * l'arrivée. Un envoi ignoré compte comme traité — le voyageur a désactivé
     * le canal, ce n'est pas une raison de réessayer indéfiniment.
     *
     * Un envoi **en échec**, lui, ne compte pas. Une panne SMTP d'une heure
     * n'est pas une décision : compter la tentative ratée comme un envoi
     * perdrait le rappel définitivement, et surtout le rendrait irrattrapable
     * — relancer la tâche depuis l'écran d'exploitation, une fois le courrier
     * rétabli, ne ferait plus rien du tout.
     *
     * Le compromis est assumé : un courrier effectivement parti mais rapporté
     * en échec — un délai d'attente dépassé après remise — sera envoyé deux
     * fois. Recevoir deux fois le rappel de son séjour est un désagrément ; ne
     * pas le recevoir du tout est une porte close devant laquelle quelqu'un
     * attend.
     */
    public function hasBeenSent(NotificationEvent $event, string $reference): bool
    {
        if ($reference === '') {
            return false;
        }

        return $this->database->fetchOne(
            'SELECT `id` FROM `notification` WHERE `event` = :event AND `reference` = :reference '
            . 'AND `status` <> :failed LIMIT 1',
            [
                'event' => $event->value,
                'reference' => mb_substr($reference, 0, 190),
                'failed' => self::FAILED,
            ]
        ) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `notification` ORDER BY `id` DESC LIMIT ' . max(1, min(500, $limit))
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEvent(NotificationEvent $event): array
    {
        return $this->database->fetchAll(
            'SELECT * FROM `notification` WHERE `event` = :event ORDER BY `id`',
            ['event' => $event->value]
        );
    }

    public function purgeOlderThan(int $days): int
    {
        return $this->database->execute(
            'DELETE FROM `notification` WHERE `created_at` < :threshold',
            ['threshold' => gmdate('Y-m-d H:i:s', time() - ($days * 86400))]
        )->rowCount();
    }
}
