<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orbs for transits to the natal chart, per workspace (docs/spec/05, Phase 7a).
 * Same shape as `aspect_orbs`; null means the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('transit_orbs')->nullable()->after('aspect_orbs');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('transit_orbs');
        });
    }
};
