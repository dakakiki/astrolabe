<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A country from GeoNames countryInfo.txt; the primary key is the ISO 3166-1
 * alpha-2 code. Reference data, shared by all workspaces.
 */
class Country extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
