<?php

namespace App\Support\Billing;

use App\Models\Payment;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payments as a CSV file for a spreadsheet or an accountant. Amounts are
 * decimal and signed — a refund is negative — so a column sum is what came
 * in. Text that a spreadsheet would read as a formula is defused.
 */
class PaymentsCsv
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * The file is written while the response is sent — after the `workspace`
     * middleware has let go of the workspace — so the rows are read inside it again.
     *
     * @param  Builder<Payment>  $payments  ordered, with client, consultation.service and appointment
     */
    public function download(Builder $payments, string $filename): StreamedResponse
    {
        $workspace = $this->current->get();

        return response()->streamDownload(fn () => $this->current->run($workspace, fn () => $this->write($payments)), $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  Builder<Payment>  $payments
     */
    private function write(Builder $payments): void
    {
        $out = fopen('php://output', 'w');

        // A byte-order mark, so spreadsheet programs read the names as UTF-8.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn (string $column) => __("payments.csv.{$column}"), [
            'date', 'client', 'type', 'amount', 'currency', 'method', 'reference', 'for', 'notes',
        ]), escape: '');

        $payments->chunk(500, function ($chunk) use ($out) {
            foreach ($chunk as $payment) {
                fputcsv($out, $this->row($payment), escape: '');
            }
        });

        fclose($out);
    }

    /**
     * @return list<string>
     */
    public function row(Payment $payment): array
    {
        return [
            $payment->paid_on->format('Y-m-d'),
            self::text($payment->client?->fullName()),
            __("payments.csv.kinds.{$payment->kind->value}"),
            self::decimal($payment->net(), $payment->currency),
            $payment->currency,
            $payment->method ? __("payments.csv.methods.{$payment->method->value}") : '',
            self::text($payment->reference),
            self::text($this->purpose($payment)),
            self::text($payment->notes),
        ];
    }

    /** "-12.50" from -1250 EUR; "4900" from 4900 JPY. No floats on the way. */
    public static function decimal(int $minorUnits, string $currency): string
    {
        $decimals = (int) config("astrolabe.currency_decimals.{$currency}", 2);
        $sign = $minorUnits < 0 ? '-' : '';
        $digits = (string) abs($minorUnits);

        if ($decimals === 0) {
            return $sign.$digits;
        }

        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);
    }

    /** A cell a spreadsheet could take for a formula starts with an apostrophe instead. */
    public static function text(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** What the payment was for: the consultation, the appointment it was a deposit for, or nothing. */
    private function purpose(Payment $payment): string
    {
        if ($payment->consultation) {
            $date = $payment->consultation->localStart()?->format('Y-m-d');
            $title = $payment->consultation->title ?? $payment->consultation->service?->name ?? '';

            return trim($title.($date ? " ({$date})" : ''));
        }

        if ($payment->appointment) {
            return __('payments.csv.deposit', ['date' => $payment->appointment->localStart()->format('Y-m-d')]);
        }

        return '';
    }
}
