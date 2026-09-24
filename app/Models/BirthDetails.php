<?php

namespace App\Models;

use App\Enums\GeocodeSource;
use App\Enums\TimeAccuracy;
use App\Models\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Birth data as entered, the only source of truth for a chart. Clients and
 * related people keep it in two tables of the same shape, so a related person
 * can become a client with the data — the frozen place included — unchanged.
 *
 * @property CarbonImmutable|null $birth_date
 * @property string|null $birth_time "HH:MM:SS"
 * @property TimeAccuracy $time_accuracy
 * @property GeocodeSource|null $geocode_source
 * @property float|null $latitude
 * @property float|null $longitude
 */
#[Fillable(BirthDetails::FIELDS)]
abstract class BirthDetails extends Model
{
    use BelongsToWorkspace;

    /** Everything that is entered; copied as a whole when a related person becomes a client. */
    public const FIELDS = [
        'birth_date', 'birth_time', 'time_accuracy', 'birth_place', 'birth_country_code',
        'latitude', 'longitude', 'birth_timezone', 'place_id', 'geocode_source', 'data_source', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'time_accuracy' => TimeAccuracy::class,
            'geocode_source' => GeocodeSource::class,
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * What is still needed before a chart can be calculated, in the order the
     * astrologer would fill it in. Empty when the chart is possible.
     *
     * @return list<string>
     */
    public function missingForChart(): array
    {
        return array_keys(array_filter([
            'birth_date' => $this->birth_date === null,
            'birth_time' => $this->time_accuracy->hasTime() && $this->birth_time === null,
            'location' => $this->latitude === null || $this->longitude === null,
            'timezone' => $this->birth_timezone === null,
        ]));
    }

    /**
     * The birth moment in its own zone, using the historical offset from tzdata
     * (war time, local DST rules). Null when date, time or zone is missing.
     */
    public function localMoment(): ?CarbonImmutable
    {
        if ($this->birth_date === null || $this->birth_time === null || $this->birth_timezone === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($this->birth_date->format('Y-m-d').' '.$this->birth_time, $this->birth_timezone);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the local birth time falls on a clock change: "skipped" when it never
     * existed (clocks went forward), "ambiguous" when it happened twice (clocks went
     * back). Either way the astrologer has to confirm which moment is meant.
     */
    public function clockChangeIssue(): ?string
    {
        $moment = $this->localMoment();

        if ($moment === null) {
            return null;
        }

        if ($moment->format('H:i:s') !== CarbonImmutable::parse($this->birth_time)->format('H:i:s')) {
            return 'skipped';
        }

        $wallClock = $moment->getTimestamp() + $moment->getOffset();
        $transitions = (new DateTimeZone($this->birth_timezone))
            ->getTransitions($moment->getTimestamp() - 3 * 3600, $moment->getTimestamp() + 3 * 3600);

        foreach (array_slice($transitions, 1, preserve_keys: true) as $index => $transition) {
            $before = $transitions[$index - 1]['offset'];
            $after = $transition['offset'];

            if ($after < $before
                && $wallClock >= $transition['ts'] + $after
                && $wallClock < $transition['ts'] + $before) {
                return 'ambiguous';
            }
        }

        return null;
    }

    /** Time-zone history before 1970 is unreliable in some regions (docs/spec/02). */
    public function hasUncertainZoneHistory(): bool
    {
        return $this->birth_date !== null && $this->birth_date->year < 1970;
    }
}
