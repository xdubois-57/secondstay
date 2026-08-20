<?php

declare(strict_types=1);

/**
 * Documents attached to a stay.
 */

return [
    'title' => 'Documents',
    'empty' => 'No document for this stay yet.',
    'name' => 'Name',
    'column_kind' => 'Type',
    'column_source' => 'Origin',
    'size' => 'Size',
    'added' => 'Added on',
    'sender' => 'Sender',
    'download' => 'Download',
    'upload' => 'Add a document',
    'file' => 'File',
    'reclassify' => 'Reclassify',
    'delete' => 'Delete',
    'uploaded' => 'Document added.',
    'reclassified' => 'Document reclassified.',
    'deleted' => 'Document deleted.',
    'booking' => 'Stay',
    'unassigned' => 'Not attached',
    'fingerprint' => 'Fingerprint',
    'kind' => [
        'contract' => 'Contract',
        'signed_contract' => 'Signed contract',
        'description' => 'Description',
        'receipt' => 'Receipt',
        'invoice' => 'Invoice',
        'proof' => 'Supporting document',
        'inventory' => 'Inventory',
        'incident' => 'Incident',
        'attachment' => 'Attachment',
        'other' => 'Other',
    ],
    'source' => [
        'generated' => 'Generated',
        'upload' => 'Uploaded',
        'mail' => 'Received by e-mail',
    ],
    'error' => [
        'empty' => 'The file is empty.',
        'too_large' => 'The file exceeds the allowed size.',
        'type' => 'This file type is not accepted.',
        'not_found' => 'Document not found.',
        'unreadable' => 'The file cannot be found on the server.',
        'upload_failed' => 'The upload failed.',
    ],
];
