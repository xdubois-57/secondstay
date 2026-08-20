<?php

declare(strict_types=1);

/**
 * Incoming mail, attachment to a stay and communication timeline.
 */

return [
    'title' => 'Messages',
    'inbound' => 'Incoming mail',
    'timeline' => 'Exchanges',
    'empty' => 'No message.',
    'unlinked' => 'Unattached messages',
    'unlinked_empty' => 'Every received message is attached.',
    'from' => 'From',
    'to' => 'To',
    'subject' => 'Subject',
    'received' => 'Received on',
    'sent' => 'Sent on',
    'attachments' => 'Attachments',
    'direction' => [
        'inbound' => 'Received',
        'outbound' => 'Sent',
    ],
    'link' => [
        'title' => 'Attachment',
        'none' => 'Not attached',
        'token' => 'Signed reply address',
        'thread' => 'Thread headers',
        'reference' => 'Quoted reference',
        'sender' => 'Sender address',
        'manual' => 'Attached manually',
    ],
    'action' => [
        'link' => 'Attach',
        'sync' => 'Poll the mailbox',
        'view' => 'View the message',
        'reference' => 'Stay reference',
    ],
    'sync' => [
        'done' => 'Poll finished: {imported} message(s), {linked} attached, {documents} document(s).',
        'nothing' => 'No new message.',
        'disabled' => 'Mailbox polling is not enabled.',
    ],
    'linked' => 'Message attached to the stay.',
    'reply_address' => 'Reply address',
    'reply_hint' => 'Just reply to this message: your reply will be attached to your stay automatically.',
    'error' => [
        'not_found' => 'Message not found.',
        'booking_not_found' => 'Stay not found.',
        'not_configured' => 'The mailbox is not configured.',
        'connection_failed' => 'Could not connect to the mailbox.',
        'greeting' => 'The server did not answer properly.',
        'tls_failed' => 'The secure connection failed.',
        'command_failed' => 'The server refused the command.',
        'connection_lost' => 'Connection lost.',
        'timeout' => 'The server stopped answering.',
        'too_large' => 'Message too large.',
        'write_failed' => 'Could not write to the server.',
    ],
    'verify' => [
        'ok' => 'Mailbox reachable.',
    ],
];
