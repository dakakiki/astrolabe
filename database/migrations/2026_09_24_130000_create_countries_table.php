<?php

use App\Astrology\Geocoding\GeoNamesCountryImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Countries are reference data every form needs, so they ship with the schema
 * (from the bundled GeoNames countryInfo.txt) instead of waiting for an import.
 * `countries:import` refreshes them from GeoNames later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->char('iso3', 3)->nullable();
            $table->string('name', 100);
            $table->string('capital', 100)->nullable();
            $table->char('continent', 2)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('phone_code', 20)->nullable();
            $table->string('languages', 200)->nullable();
            $table->unsignedBigInteger('population')->default(0);
            $table->unsignedInteger('geoname_id')->nullable();
        });

        app(GeoNamesCountryImporter::class)->import(database_path('data/geonames/countryInfo.txt'));
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
