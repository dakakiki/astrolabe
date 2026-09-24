<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For the full gazetteer (every populated place, ~5 million): the district
 * tells same-named villages apart, and "major" lets short prefixes search the
 * towns people usually mean without scanning every hamlet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->string('admin2_code', 80)->nullable()->after('admin1_name');
            $table->string('admin2_name', 200)->nullable()->after('admin2_code');
        });

        Schema::table('place_names', function (Blueprint $table) {
            $table->boolean('major')->default(false);
            $table->index(['major', 'search_name'], 'place_names_major_search_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('place_names', function (Blueprint $table) {
            $table->dropIndex('place_names_major_search_name_index');
            $table->dropColumn('major');
        });

        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn(['admin2_code', 'admin2_name']);
        });
    }
};
