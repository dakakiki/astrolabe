<?php

return [
    'other_client' => 'This belongs to another client.',
    'appointment_other_consultation' => 'This appointment was recorded as another consultation.',
    'currency_mismatch' => 'Payments here are in :currency.',
    'refund_too_large' => 'A refund cannot be more than was received.',
    'fee_currency_mismatch' => 'Payments for this consultation are in :currency.',

    // The CSV export: column headings and values, in the viewer's language.
    'csv' => [
        'date' => 'Date',
        'client' => 'Client',
        'type' => 'Type',
        'amount' => 'Amount',
        'currency' => 'Currency',
        'method' => 'Method',
        'reference' => 'Reference',
        'for' => 'For',
        'notes' => 'Notes',
        'kinds' => [
            'payment' => 'Payment',
            'refund' => 'Refund',
        ],
        'methods' => [
            'bank_transfer' => 'Bank transfer',
            'card' => 'Card',
            'cash' => 'Cash',
            'paypal' => 'PayPal',
            'other' => 'Other',
        ],
        'deposit' => 'Deposit for :date',
    ],
];
