<?php

declare(strict_types=1);

/**
 * Documents rattachés à un séjour.
 */

return [
    'title' => 'Documents',
    'empty' => 'Aucun document pour ce séjour.',
    'name' => 'Nom',
    'column_kind' => 'Nature',
    'column_source' => 'Provenance',
    'size' => 'Taille',
    'added' => 'Ajouté le',
    'sender' => 'Expéditeur',
    'download' => 'Télécharger',
    'upload' => 'Ajouter un document',
    'file' => 'Fichier',
    'reclassify' => 'Reclasser',
    'delete' => 'Supprimer',
    'uploaded' => 'Document ajouté.',
    'reclassified' => 'Document reclassé.',
    'deleted' => 'Document supprimé.',
    'booking' => 'Séjour',
    'unassigned' => 'Non rattaché',
    'fingerprint' => 'Empreinte',
    'kind' => [
        'contract' => 'Contrat',
        'signed_contract' => 'Contrat signé',
        'description' => 'Descriptif',
        'receipt' => 'Reçu',
        'invoice' => 'Facture',
        'proof' => 'Justificatif',
        'inventory' => 'État des lieux',
        'incident' => 'Incident',
        'attachment' => 'Pièce jointe',
        'other' => 'Autre',
    ],
    'source' => [
        'generated' => 'Généré',
        'upload' => 'Déposé',
        'mail' => 'Reçu par e-mail',
    ],
    'error' => [
        'empty' => 'Le fichier est vide.',
        'too_large' => 'Le fichier dépasse la taille autorisée.',
        'type' => 'Ce type de fichier n’est pas accepté.',
        'not_found' => 'Document introuvable.',
        'unreadable' => 'Le fichier est introuvable sur le serveur.',
        'upload_failed' => 'Le dépôt a échoué.',
    ],
];
