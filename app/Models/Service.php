<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Enums\ServiceColor;
use App\Models\Concerns\BelongsToWorkspace;
use App\Support\Activity\ActivityProjector;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the practice offers (docs/spec/02, "Usluge"): how long it takes, what
 * it costs and where it is held. A service in use is deactivated rather than
 * deleted, so past consultations keep it.
 *
 * @property LocationType $location_type
 * @property ServiceColor|null $color
 * @property int|null $price_amount in the currency's smallest unit
 */
#[Fillable([
    'name', 'description', 'duration_minutes', 'price_amount', 'currency',
    'location_type', 'color', 'requires_deposit', 'is_active',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToWorkspace, HasFactory;

    /** @var array<string, mixed> the column defaults, known before the row is read back */
    protected $attributes = [
        'requires_deposit' => false,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        // A consultation without a title appears on the timeline under the service's name.
        static::updated(function (Service $service) {
            if ($service->wasChanged('name')) {
                $service->consultations()->with('service')->chunkById(200, function ($consultations) {
                    $consultations->each(fn (Consultation $consultation) => app(ActivityProjector::class)->sync($consultation));
                });
            }
        });
    }

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price_amount' => 'integer',
            'location_type' => LocationType::class,
            'color' => ServiceColor::class,
            'requires_deposit' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Methods this service is meant for; none means any.
     *
     * @return BelongsToMany<AstrologyMethod, $this>
     */
    public function astrologyMethods(): BelongsToMany
    {
        return $this->belongsToMany(AstrologyMethod::class, 'service_astrology_method');
    }

    /**
     * @return HasMany<Consultation, $this>
     */
    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    /** Whether anything refers to it, deleted consultations included (the foreign key sees those too). */
    public function isInUse(): bool
    {
        return $this->consultations()->withTrashed()->exists();
    }
}
