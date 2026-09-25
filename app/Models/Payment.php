<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\ProjectsActivity;
use App\Support\Activity\ActivityProjection;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Money a client paid, or money given back (docs/spec/02, "Plaćanja"). The app
 * records payments, it does not process them. A payment may be for a
 * consultation, or — as a deposit — for an appointment; the deposit moves to
 * the consultation recorded from that appointment. What a consultation still
 * owes is derived from its fee and these rows.
 *
 * @property PaymentKind $kind
 * @property PaymentMethod|null $method
 * @property CarbonImmutable $paid_on the day the money arrived, as entered
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToWorkspace, HasFactory, ProjectsActivity, SoftDeletes;

    /** Set by SavePayment, never mass-assigned from a request. */
    protected $guarded = ['id', 'workspace_id'];

    /** Received less refunded, as SQL over a payments row. */
    public const NET_SQL = "case when kind = 'refund' then -amount else amount end";

    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'paid_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Consultation, $this>
     */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /**
     * The appointment a deposit was paid for.
     *
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The amount as it counts towards what was paid: negative for a refund. */
    public function net(): int
    {
        return $this->kind->sign() * $this->amount;
    }

    /**
     * A subquery for what came in net for each row of `$column` (a
     * consultation or appointment id): `select sum(…) from payments where …`.
     * Built on the plain query builder, so it can sit inside another query.
     */
    public static function netFor(QueryBuilder $query, string $column, string $foreignKey = 'consultation_id'): QueryBuilder
    {
        return $query->from('payments')
            ->selectRaw('coalesce(sum('.self::NET_SQL.'), 0)')
            ->whereColumn("payments.{$foreignKey}", $column)
            ->whereNull('payments.deleted_at');
    }

    public static function activityType(): ActivityType
    {
        return ActivityType::Payment;
    }

    /**
     * The payment on its client's timeline, on the day it arrived. The day has no
     * time; noon UTC keeps it on that date for everyone reading the timeline.
     */
    public function activityProjection(): ActivityProjection
    {
        return new ActivityProjection(
            type: self::activityType(),
            clientId: $this->client_id,
            occurredAt: $this->paid_on->setTime(12, 0),
            createdBy: $this->created_by,
            summary: $this->reference,
            metadata: [
                'kind' => $this->kind->value,
                'amount' => $this->amount,
                'currency' => $this->currency,
                'paid_on' => $this->paid_on->format('Y-m-d'),
                'method' => $this->method?->value,
                'consultation_id' => $this->consultation_id,
                'appointment_id' => $this->appointment_id,
            ],
        );
    }
}
