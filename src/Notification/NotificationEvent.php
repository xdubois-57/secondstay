<?php

declare(strict_types=1);

namespace SecondStay\Notification;

/**
 * Événements métier notifiables (SPECIFICATIONS.md §42).
 *
 * Les itérations suivantes branchent leurs propres déclencheurs sur ces
 * valeurs : le canal, la langue et la journalisation sont déjà résolus ici.
 */
enum NotificationEvent: string
{
    case AccountConfirmed = 'account_confirmed';
    /**
     * Envoi de vérification déclenché par le titulaire du compte : c'est le
     * seul moyen de savoir qu'un appareil reçoit réellement les
     * notifications avant qu'un événement réel ne survienne.
     */
    case Test = 'test';
    case BookingCreated = 'booking_created';
    case BookingConfirmed = 'booking_confirmed';
    case PaymentReceived = 'payment_received';
    case StayReminder = 'stay_reminder';
    case Arrival = 'arrival';
    case Departure = 'departure';
    case Incident = 'incident';
    case TaskAssigned = 'task_assigned';

    public const MAIL_TEMPLATE = 'notification';

    /**
     * Toutes les notifications partagent un gabarit e-mail unique, alimenté
     * par les clés de traduction de l'événement : ajouter un événement ne
     * demande donc qu'un jeu de traductions, jamais un gabarit de plus.
     */
    public function mailTemplate(): string
    {
        return self::MAIL_TEMPLATE;
    }

    public function subjectKey(): string
    {
        return 'notification.' . $this->value . '.subject';
    }

    /**
     * Clé de traduction du titre poussé.
     */
    public function titleKey(): string
    {
        return 'notification.' . $this->value . '.title';
    }

    public function bodyKey(): string
    {
        return 'notification.' . $this->value . '.body';
    }

    /**
     * Le corps d'un e-mail peut être plus long que celui d'une notification
     * poussée : les deux textes sont donc distincts.
     */
    public function mailBodyKey(): string
    {
        return 'notification.' . $this->value . '.mail_body';
    }

    public function actionKey(): string
    {
        return 'notification.' . $this->value . '.action';
    }

    /**
     * Un événement d'exploitation ne concerne que les rôles opérationnels ;
     * les autres s'adressent au client.
     */
    public function isOperational(): bool
    {
        return match ($this) {
            self::Incident, self::TaskAssigned => true,
            default => false,
        };
    }
}
