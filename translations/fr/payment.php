<?php

declare(strict_types=1);

/**
 * Paiements : échéancier, encaissements, caution et virement SEPA.
 */

return [
    'kind' => [
        'deposit' => 'Acompte',
        'balance' => 'Solde',
        'security_deposit' => 'Caution',
        'cleaning' => 'Ménage',
        'tourist_tax' => 'Taxe de séjour',
        'adjustment' => 'Ajustement',
        'refund' => 'Remboursement',
    ],
    'status' => [
        'pending' => 'En attente',
        'authorized' => 'Autorisé',
        'paid' => 'Payé',
        'failed' => 'Échoué',
        'cancelled' => 'Annulé',
        'refunded' => 'Remboursé',
        'partially_refunded' => 'Partiellement remboursé',
    ],
    'hold' => [
        'none' => '—',
        'to_pay' => 'À payer',
        'received' => 'Reçue',
        'to_return' => 'À restituer',
        'returned' => 'Restituée',
        'partially_retained' => 'Partiellement retenue',
    ],
    'schedule' => [
        'title' => 'Échéancier',
        'empty' => 'Aucun paiement n’est encore attendu.',
        'due_on' => 'Échéance',
        'amount' => 'Montant',
        'component' => 'Composant',
        'state' => 'État',
        'overdue' => 'En retard',
        'total_due' => 'Reste à payer',
        'total_paid' => 'Déjà réglé',
    ],
    'action' => [
        'pay_online' => 'Payer en ligne',
        'pay_transfer' => 'Payer par virement',
        'back_to_booking' => 'Revenir à la réservation',
        'record' => 'Enregistrer l’encaissement',
        'refund' => 'Rembourser',
        'schedule' => 'Recalculer l’échéancier',
        'mark_to_return' => 'Marquer la caution à restituer',
    ],
    'transfer' => [
        'title' => 'Payer par virement',
        'intro' => 'Scannez ce QR code avec votre application bancaire, ou recopiez les coordonnées ci-dessous.',
        'beneficiary' => 'Bénéficiaire',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'reference' => 'Référence à indiquer',
        'qr_alt' => 'QR code de virement SEPA',
        'notice' => 'Un virement met un à deux jours ouvrés à parvenir. La réservation est confirmée après '
            . 'vérification.',
    ],
    'return' => [
        'title' => 'Retour de paiement',
        'paid' => 'Votre paiement a bien été reçu.',
        'pending' => 'Votre paiement est en cours de traitement. Cette page se mettra à jour dès sa confirmation.',
        'failed' => 'Le paiement n’a pas abouti. Vous pouvez réessayer.',
    ],
    'admin' => [
        'title' => 'Paiements',
        'outstanding' => 'Échéances en attente',
        'held_deposits' => 'Cautions détenues',
        'webhooks' => 'Notifications reçues',
        'booking' => 'Réservation',
        'provider' => 'Fournisseur',
        'provider_ready' => 'Fournisseur configuré',
        'provider_missing' => 'Aucun fournisseur configuré : seuls les virements et encaissements manuels sont '
            . 'possibles.',
        'confirm_booking' => 'Confirmer aussi la réservation',
        'reason' => 'Motif',
        'scheduled' => 'Échéancier mis à jour.',
        'recorded' => 'Encaissement enregistré.',
        'refunded' => 'Remboursement effectué.',
        'hold_updated' => 'Caution mise à jour.',
        'empty' => 'Rien à encaisser pour le moment.',
        'received_at' => 'Reçue le',
    ],
    'error' => [
        'already_settled' => 'Ce paiement est déjà réglé.',
        'not_settled' => 'Ce paiement n’a pas été encaissé.',
        'not_configured' => 'Aucun fournisseur de paiement n’est configuré.',
        'invalid_webhook' => 'Notification illisible.',
        'provider_unreachable' => 'Le fournisseur de paiement est injoignable.',
        'refund_amount' => 'Montant de remboursement invalide.',
        'hold_transition' => 'Transition de caution impossible.',
        'not_found' => 'Paiement introuvable.',
    ],
];
