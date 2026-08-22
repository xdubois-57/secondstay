<?php

declare(strict_types=1);

namespace SecondStay\Scheduler;

/**
 * Tâches périodiques du produit (ARCHITECTURE.md §23).
 *
 * Aucun worker permanent n'est requis : chaque tâche est courte et
 * idempotente, et une seule entrée cron les déclenche toutes. L'intervalle
 * porté ici est un **minimum** entre deux exécutions, pas une promesse
 * d'horaire : sur un hébergement mutualisé, le cron peut passer toutes les
 * quinze minutes comme toutes les heures, et le planificateur doit rester
 * correct dans les deux cas.
 */
enum ScheduledTask: string
{
    /**
     * Libération des verrous de réservation expirés.
     *
     * C'est la tâche la plus fréquente parce que c'est la seule dont le retard
     * se voit du côté public : une nuit verrouillée par un panier abandonné
     * reste invendable tant que le verrou n'a pas été libéré.
     */
    case BookingHolds = 'booking_holds';

    /** Relève de la boîte de réception dédiée (SPECIFICATIONS.md §36). */
    case InboundMail = 'inbound_mail';

    /** Synchronisation des calendriers externes importés (§52). */
    case CalendarImport = 'calendar_import';

    /** Génération du contenu local des séjours à venir (§56). */
    case LocalContent = 'local_content';

    /** Rappels de séjour, arrivées et départs (§42). */
    case StayReminders = 'stay_reminders';

    /** Purge des données arrivées à échéance de rétention (§65). */
    case Retention = 'retention';

    /** Sauvegarde automatique et application de la rétention (§67). */
    case Backup = 'backup';

    /** Contrôle de disponibilité d'une mise à jour (§69). */
    case UpdateCheck = 'update_check';

    /**
     * Intervalle minimal entre deux exécutions, en minutes.
     */
    public function intervalMinutes(): int
    {
        return match ($this) {
            self::BookingHolds => 10,
            self::InboundMail => 15,
            self::CalendarImport => 60,
            self::LocalContent, self::StayReminders, self::Retention, self::Backup, self::UpdateCheck => 1440,
        };
    }

    /**
     * Retard en deçà duquel aucune tâche n'est jamais signalée.
     *
     * Trois intervalles suffisent pour les tâches lentes, mais pas pour les
     * rapides : `booking_holds` veut passer toutes les dix minutes, or une
     * bonne partie des hébergements mutualisés n'offre qu'un cron **horaire**.
     * Le seuil brut de trente minutes y produirait une alerte permanente sur
     * une installation qui fonctionne — et un écran de diagnostics rouge en
     * permanence est un écran qu'on cesse de lire, ce qui coûte plus cher que
     * l'absence de diagnostic.
     *
     * Trois heures est donc le plancher : au-delà, même un cron horaire a
     * réellement cessé de passer.
     */
    public const MINIMUM_STALE_MINUTES = 180;

    /**
     * Retard à partir duquel la tâche est signalée comme en souffrance.
     *
     * Trois intervalles — un cron qui passe une fois est un incident
     * d'hébergement banal, trois fois de suite est une panne — sans jamais
     * descendre sous le plancher qui rend un cron horaire acceptable.
     */
    public function staleAfterMinutes(): int
    {
        return max($this->intervalMinutes() * 3, self::MINIMUM_STALE_MINUTES);
    }

    /**
     * Durée au-delà de laquelle le verrou d'exécution est considéré comme
     * abandonné. Un processus tué par l'hébergeur ne doit pas condamner sa
     * tâche définitivement.
     */
    public function lockMinutes(): int
    {
        return match ($this) {
            self::Backup => 60,
            self::LocalContent => 30,
            default => 15,
        };
    }

    /**
     * Clé de traduction du libellé.
     */
    public function labelKey(): string
    {
        return 'scheduler.task.' . $this->value;
    }

    /**
     * @return list<ScheduledTask>
     */
    public static function all(): array
    {
        return self::cases();
    }

    public static function tryFromCode(string $code): ?self
    {
        return self::tryFrom($code);
    }
}
