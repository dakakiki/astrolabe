<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local copy of the GeoNames gazetteer (docs/spec/11, "Geokodiranje").
 * Shared reference data, not tenant data: refreshed by `places:import`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            // The GeoNames id, so a place keeps its identity across imports.
            $table->unsignedInteger('id')->primary();
            $table->string('name', 200);
            $table->string('ascii_name', 200);
            $table->char('country_code', 2)->nullable();
            $table->string('admin1_code', 20)->nullable();
            $table->string('admin1_name', 200)->nullable();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->string('timezone', 40)->nullable();
            $table->unsignedBigInteger('population')->default(0);
            $table->string('feature_code', 10)->nullable();
            $table->date('modified_on')->nullable();

            $table->index(['latitude', 'longitude']);
        });

        // Every spelling a place can be found by (official, ASCII, other languages
        // and scripts, historic names), normalised for prefix search.
        Schema::create('place_names', function (Blueprint $table) {
            $table->unsignedInteger('place_id');
            $table->string('search_name', 200);

            $table->primary(['search_name', 'place_id']);
            $table->index('place_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_names');
        Schema::dropIfExists('places');
    }
};
