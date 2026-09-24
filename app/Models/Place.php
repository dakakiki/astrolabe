<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A populated place from the local GeoNames gazetteer. Shared reference data;
 * the id is the GeoNames id.
 *
 * @property float $latitude
 * @property float $longitude
 */
class Place extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'population' => 'integer',
            'modified_on' => 'date',
        ];
    }

    /** "Novi Sad, Vojvodina" — the country is shown separately, in the viewer's language. */
    public function label(): string
    {
        return collect([$this->name, $this->admin1_name])
            ->filter()
            ->unique()
            ->implode(', ');
    }
}
