<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\BillingStatus;
use App\Enums\ConsultationStatus;
use App\Enums\PaymentKind;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\ProjectsActivity;
use App\Support\Activity\ActivityProjection;
use App\Support\Activity\ActivityProjector;
use Carbon\CarbonImmutable;
use Database\Factories\ConsultationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

/**
 * The professional record of one session with a client (docs/spec/02):
 * topics, internal notes, the summary meant for the client, next steps, the
 * methods used and, optionally, the chart as it stood that day.
 *
 * The client is set once, on creation: notes, files and the chart snapshot
 * all belong to that client.
 *
 * The fee (Phase 7b) is what the session costs the client: from its service,
 * editable, 0 for no charge, null when none was set. What was paid and what
 * is still owed come from the payments against it (`billing()`).
 *
 * @property ConsultationStatus $status
 * @property CarbonImmutable|null $starts_at UTC
 * @property int|null $fee_amount smallest currency unit
 */
#[Fillable([
    'service_id', 'title', 'starts_at', 'timezone', 'duration_minutes', 'status',
    'topics', 'internal_notes', 'client_summary', 'next_steps',
])]
class Consultation extends Model
{
    /** @use HasFactory<ConsultationFactory> */
    use BelongsToWorkspace, HasFactory, ProjectsActivity, SoftDeletes;

    protected static function booted(): void
    {
        // The appointment's timeline entry gives way to the consultation recorded
        // from it, and comes back if the consultation is deleted.
        $syncAppointment = function (Consultation $consultation) {
            $ids = array_filter([$consultation->appointment_id, $consultation->getOriginal('appointment_id')]);

            Appointment::withoutGlobalScopes()->whereKey($ids)->get()
                ->each(fn (Appointment $appointment) => app(ActivityProjector::class)->sync($appointment));
        };

        static::saved($syncAppointment);
        static::deleted($syncAppointment);
    }

    protected function casts(): array
    {
        return [
            'status' => ConsultationStatus::class,
            'starts_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
            'fee_amount' => 'integer',
        ];
    }

    /** What a single consultation is shown with (`ConsultationResource::withContent`). */
    public const DETAIL_RELATIONS = ['client', 'service', 'appointment', 'astrologyMethods', 'chart', 'payments'];

    /** Statuses whose fee is due: the session took place, or the client did not come. */
    public const DUE_STATUSES = [ConsultationStatus::Completed, ConsultationStatus::NoShow];

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('id');
    }

    /**
     * Adds what was received net (`billing_paid`), refunded (`billing_refunded`)
     * and in which currency (`billing_currency`) to each row, in the same
     * query, so a list can show every consultation's billing.
     *
     * @param  Builder<Consultation>  $query
     */
    public function scopeWithBilling(Builder $query): void
    {
        $query->addSelect([
            'billing_paid' => fn (QueryBuilder $sub) => Payment::netFor($sub, 'consultations.id'),
            'billing_refunded' => fn (QueryBuilder $sub) => $sub->from('payments')
                ->selectRaw('coalesce(sum(amount), 0)')
                ->whereColumn('payments.consultation_id', 'consultations.id')
                ->where('payments.kind', PaymentKind::Refund->value)
                ->whereNull('payments.deleted_at'),
            'billing_currency' => fn (QueryBuilder $sub) => $sub->from('payments')
                ->selectRaw('min(currency)')
                ->whereColumn('payments.consultation_id', 'consultations.id')
                ->whereNull('payments.deleted_at'),
        ]);
    }

    /**
     * Held (or missed) with part of the fee still to come: what "waiting on
     * payment" and "outstanding" count.
     *
     * @param  Builder<Consultation>  $query
     */
    public function scopeOwed(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (ConsultationStatus $status) => $status->value, self::DUE_STATUSES))
            ->where('fee_amount', '>', 0)
            ->where('fee_amount', '>', fn (QueryBuilder $sub) => Payment::netFor($sub, 'consultations.id'));
    }

    /** The fee as money, or null when none is set. */
    public function fee(): ?array
    {
        return $this->fee_amount === null ? null : ['amount' => $this->fee_amount, 'currency' => $this->fee_currency];
    }

    /**
     * Where the fee stands: the status, what came in net, what was refunded,
     * the balance still to pay (negative when more came in) and whether that
     * balance is owed now. Reads the `withBilling()` columns when the row has
     * them, otherwise asks the database.
     *
     * @return array{status: string|null, paid: array{amount: int, currency: string}|null, refunded: array{amount: int, currency: string}|null, balance: array{amount: int, currency: string}|null, owed: bool}
     */
    public function billing(): array
    {
        if (array_key_exists('billing_paid', $this->attributes)) {
            $paid = (int) $this->attributes['billing_paid'];
            $refunded = (int) $this->attributes['billing_refunded'];
            $currency = $this->attributes['billing_currency'];
        } else {
            $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get(['kind', 'amount', 'currency']);
            $paid = (int) $payments->sum(fn (Payment $payment) => $payment->net());
            $refunded = (int) $payments->where('kind', PaymentKind::Refund)->sum('amount');
            $currency = $payments->min('currency');
        }

        $currency = $this->fee_currency ?? $currency;
        $money = fn (int $amount) => $currency === null ? null : ['amount' => $amount, 'currency' => $currency];
        $balance = $this->fee_amount === null ? null : $this->fee_amount - $paid;

        return [
            'status' => BillingStatus::derive($this->fee_amount, $paid, $refunded > 0)?->value,
            'paid' => $money($paid),
            'refunded' => $refunded > 0 ? $money($refunded) : null,
            'balance' => $balance === null ? null : $money($balance),
            'owed' => $balance !== null && $balance > 0 && $this->fee_amount > 0
                && in_array($this->status, self::DUE_STATUSES, true),
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The calendar appointment this consultation was recorded from, if any.
     *
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The chart snapshot attached to this consultation.
     *
     * @return BelongsTo<ChartCalculation, $this>
     */
    public function chart(): BelongsTo
    {
        return $this->belongsTo(ChartCalculation::class, 'chart_calculation_id');
    }

    /**
     * @return BelongsToMany<AstrologyMethod, $this>
     */
    public function astrologyMethods(): BelongsToMany
    {
        return $this->belongsToMany(AstrologyMethod::class, 'consultation_astrology_method');
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** The start as it was entered, in its own zone. */
    public function localStart(): ?CarbonImmutable
    {
        return $this->starts_at?->setTimezone($this->timezone ?? 'UTC');
    }

    public static function activityType(): ActivityType
    {
        return ActivityType::Consultation;
    }

    public function activityProjection(): ActivityProjection
    {
        // Projections are kept in step outside a request too (activity:rebuild, jobs),
        // where no workspace scope applies; the service is the consultation's own.
        $service = $this->service_id === null ? null : ($this->relationLoaded('service')
            ? $this->service
            : $this->service()->withoutGlobalScopes()->first());

        return new ActivityProjection(
            type: self::activityType(),
            clientId: $this->client_id,
            occurredAt: $this->starts_at ?? $this->created_at,
            createdBy: $this->created_by,
            summary: $this->title ?? $service?->name,
            metadata: [
                'status' => $this->status->value,
                'title' => $this->title,
                'service' => $service?->name,
                'duration_minutes' => $this->duration_minutes,
                'timezone' => $this->timezone,
                'has_chart' => $this->chart_calculation_id !== null,
                'topics' => $this->topics === null ? null : Str::limit(trim(preg_replace('/\s+/u', ' ', $this->topics) ?? ''), 160),
            ],
        );
    }
}
