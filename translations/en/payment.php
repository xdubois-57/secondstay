<?php

declare(strict_types=1);

/**
 * Payments: schedule, collection, security deposit and SEPA transfer.
 */

return [
    'kind' => [
        'deposit' => 'Deposit',
        'balance' => 'Balance',
        'security_deposit' => 'Security deposit',
        'cleaning' => 'Cleaning',
        'tourist_tax' => 'Tourist tax',
        'adjustment' => 'Adjustment',
        'refund' => 'Refund',
    ],
    'status' => [
        'pending' => 'Pending',
        'authorized' => 'Authorised',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partially refunded',
    ],
    'hold' => [
        'none' => '—',
        'to_pay' => 'To pay',
        'received' => 'Received',
        'to_return' => 'To return',
        'returned' => 'Returned',
        'partially_retained' => 'Partially retained',
    ],
    'schedule' => [
        'title' => 'Payment schedule',
        'empty' => 'No payment is expected yet.',
        'due_on' => 'Due',
        'amount' => 'Amount',
        'component' => 'Component',
        'state' => 'State',
        'overdue' => 'Overdue',
        'total_due' => 'Left to pay',
        'total_paid' => 'Already paid',
    ],
    'action' => [
        'pay_online' => 'Pay online',
        'pay_transfer' => 'Pay by bank transfer',
        'back_to_booking' => 'Back to the booking',
        'record' => 'Record the payment',
        'refund' => 'Refund',
        'schedule' => 'Rebuild the schedule',
        'mark_to_return' => 'Mark the deposit to return',
    ],
    'transfer' => [
        'title' => 'Pay by bank transfer',
        'intro' => 'Scan this QR code with your banking app, or copy the details below.',
        'beneficiary' => 'Beneficiary',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'reference' => 'Reference to quote',
        'qr_alt' => 'SEPA transfer QR code',
        'notice' => 'A transfer takes one or two working days to arrive. The booking is confirmed once it has been '
            . 'checked.',
    ],
    'return' => [
        'title' => 'Payment result',
        'paid' => 'Your payment has been received.',
        'pending' => 'Your payment is being processed. This page will update as soon as it is confirmed.',
        'failed' => 'The payment did not go through. You can try again.',
    ],
    'admin' => [
        'title' => 'Payments',
        'outstanding' => 'Outstanding instalments',
        'held_deposits' => 'Security deposits held',
        'webhooks' => 'Notifications received',
        'booking' => 'Booking',
        'provider' => 'Provider',
        'provider_ready' => 'Provider configured',
        'provider_missing' => 'No provider configured: only transfers and manual collection are possible.',
        'confirm_booking' => 'Also confirm the booking',
        'reason' => 'Reason',
        'scheduled' => 'Schedule updated.',
        'recorded' => 'Payment recorded.',
        'refunded' => 'Refund issued.',
        'hold_updated' => 'Security deposit updated.',
        'empty' => 'Nothing to collect right now.',
        'received_at' => 'Received on',
    ],
    'error' => [
        'already_settled' => 'This payment has already been settled.',
        'not_settled' => 'This payment has not been collected.',
        'not_configured' => 'No payment provider is configured.',
        'invalid_webhook' => 'Unreadable notification.',
        'provider_unreachable' => 'The payment provider cannot be reached.',
        'refund_amount' => 'Invalid refund amount.',
        'hold_transition' => 'That security deposit transition is not allowed.',
        'not_found' => 'Payment not found.',
    ],
];
