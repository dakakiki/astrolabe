<?php

namespace App\Enums;

enum GeocodeSource: string
{
    /** Copied from the local GeoNames gazetteer. */
    case GeoNames = 'geonames';

    /** Coordinates and time zone entered or corrected by hand. */
    case Manual = 'manual';
}
