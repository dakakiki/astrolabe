<?php

namespace App\Support\Billing;

use App\Models\Consultation;
use App\Models\Payment;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The practice's money in figures (docs/spec/02, "Plaćanja" and "Dashboard"):
 * what came in over a period and what clients still owe. Amounts stay in their
 * own currencies — nothing is converted — so every figure is a list of money,
 * the workspace's default currency first.
 */
class Ledger
{
    public function __construct(private readonly CurrentWorkspace $workspace) {}

    /**
     * Received less refunded, per currency, for the payments of a query.
     *
     * @param  Builder<Payment>  $payments
     * @return list<array{amount: int, currency: string}>
     */
    public function received(Builder $payments): array
    {
        return $this->sorted(
            (clone $payments)->toBase()
                ->reorder()
                ->selectRaw('currency, sum('.Payment::NET_SQL.') as amount')
                ->groupBy('currency')
                ->pluck('amount', 'currency'),
        );
    }

    /**
     * Received less refunded, per currency, between two days on the
     * astrologer's calendar (both included).
     *
     * @return list<array{amount: int, currency: string}>
     */
    public function receivedBetween(string $from, string $to): array
    {
        return $this->received(Payment::query()->whereBetween('paid_on', [$from, $to]));
    }

    /**
     * What held consultations still owe, per currency, and how many owe it.
     *
     * @param  Builder<Consultation>|null  $consultations  to narrow it, e.g. to one client
     * @return array{total: list<array{amount: int, currency: string}>, count: int}
     */
    public function outstanding(?Builder $consultations = null): array
    {
        $owed = ($consultations ?? Consultation::query())->owed()->withBilling()->get();

        return [
            'total' => $this->sorted($owed
                ->groupBy('fee_currency')
                ->map(fn (Collection $group) => $group->sum(fn (Consultation $consultation) => $consultation->fee_amount - (int) $consultation->billing_paid))),
            'count' => $owed->count(),
        ];
    }

    /**
     * Money by currency as a list, the workspace's currency first, then A–Z;
     * currencies that net to nothing are left out.
     *
     * @param  iterable<string, int|string>  $byCurrency
     * @return list<array{amount: int, currency: string}>
     */
    public function sorted(iterable $byCurrency): array
    {
        $default = $this->workspace->get()?->default_currency;

        return collect($byCurrency)
            ->map(fn ($amount, $currency) => ['amount' => (int) $amount, 'currency' => (string) $currency])
            ->filter(fn (array $money) => $money['amount'] !== 0)
            ->sortBy(fn (array $money) => [$money['currency'] === $default ? 0 : 1, $money['currency']])
            ->values()
            ->all();
    }
}
