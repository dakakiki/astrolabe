<?php

namespace App\Enums;

/**
 * How the money arrived. Recorded only — the app does not process payments.
 */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Cash = 'cash';
    case PayPal = 'paypal';
    case Other = 'other';
}
