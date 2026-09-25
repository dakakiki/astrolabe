<?php

namespace Tests\Unit;

use App\Enums\BillingStatus;
use App\Support\Billing\PaymentsCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A consultation's billing status from its fee and payments, and the CSV
 * export's number and text cells (Phase 7b).
 */
class BillingTest extends TestCase
{
    /**
     * @return array<string, array{int|null, int, bool, BillingStatus|null}>
     */
    public static function statuses(): array
    {
        return [
            'no fee, no money' => [null, 0, false, null],
            'no fee, money came' => [null, 5000, false, BillingStatus::Paid],
            'no charge' => [0, 0, false, BillingStatus::NoCharge],
            'no charge wins over money' => [0, 5000, false, BillingStatus::NoCharge],
            'nothing yet' => [12000, 0, false, BillingStatus::Unpaid],
            'part of it' => [12000, 3000, false, BillingStatus::PartiallyPaid],
            'all of it' => [12000, 12000, false, BillingStatus::Paid],
            'more than asked' => [12000, 15000, false, BillingStatus::Paid],
            'all given back' => [12000, 0, true, BillingStatus::Refunded],
            'part given back' => [12000, 7000, true, BillingStatus::PartiallyPaid],
        ];
    }

    #[DataProvider('statuses')]
    public function test_the_status_follows_the_fee_and_the_net_received(?int $fee, int $paid, bool $refunded, ?BillingStatus $expected): void
    {
        $this->assertSame($expected, BillingStatus::derive($fee, $paid, $refunded));
    }

    public function test_amounts_are_decimal_and_signed_without_floats(): void
    {
        $this->assertSame('120.50', PaymentsCsv::decimal(12050, 'EUR'));
        $this->assertSame('-0.05', PaymentsCsv::decimal(-5, 'EUR'));
        $this->assertSame('0.29', PaymentsCsv::decimal(29, 'EUR'));
        $this->assertSame('4900', PaymentsCsv::decimal(4900, 'JPY'));
        $this->assertSame('-4900', PaymentsCsv::decimal(-4900, 'JPY'));
    }

    public function test_text_a_spreadsheet_could_run_is_defused(): void
    {
        $this->assertSame("'=SUM(A1:A9)", PaymentsCsv::text('=SUM(A1:A9)'));
        $this->assertSame("'+381 64 123", PaymentsCsv::text('+381 64 123'));
        $this->assertSame("'-2", PaymentsCsv::text('-2'));
        $this->assertSame("'@cmd", PaymentsCsv::text('@cmd'));
        $this->assertSame('INV-2026-7', PaymentsCsv::text('INV-2026-7'));
        $this->assertSame('', PaymentsCsv::text(null));
    }
}
