<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interface languages
    |--------------------------------------------------------------------------
    |
    | English is the default and the fallback (docs/spec/06). A locale is added
    | here only once both the frontend (resources/js/i18n/locales) and the
    | backend (lang/) translations exist. Names are shown in their own language.
    |
    */

    'locales' => [
        'en' => 'English',
    ],

    /*
    |--------------------------------------------------------------------------
    | Currencies
    |--------------------------------------------------------------------------
    |
    | ISO 4217 codes a workspace may choose as its default currency. Display
    | names come from the browser's Intl API, so only the codes live here.
    |
    */

    'currencies' => [
        'EUR', 'USD', 'GBP', 'CHF', 'RSD', 'BAM', 'HRK', 'MKD', 'HUF', 'CZK',
        'PLN', 'RON', 'BGN', 'SEK', 'NOK', 'DKK', 'ISK', 'TRY', 'UAH', 'CAD',
        'AUD', 'NZD', 'JPY', 'CNY', 'HKD', 'SGD', 'INR', 'ZAR', 'BRL', 'MXN',
        'ARS', 'CLP', 'COP', 'ILS', 'AED',
    ],

    'default_currency' => 'EUR',

    /*
    |--------------------------------------------------------------------------
    | Birth places
    |--------------------------------------------------------------------------
    |
    | The local GeoNames gazetteer (`php artisan places:import`). "all" holds
    | every populated place (~5.2 million, ~1 GB, ~13 minutes to import) and is
    | what production uses: "cities500" has only 492 places in Serbia, against
    | some 4,700 settlements. "cities500" (~14 MB) is a quick option for development.
    |
    */

    'places' => [
        'source' => env('PLACES_SOURCE', 'all'),
    ],

];
