<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aspect orbs per workspace (docs/spec/05, Phase 5). Null means the defaults;
 * stored values are laid over them (App\Astrology\ValueObjects\AspectSettings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('aspect_orbs')->nullable()->after('default_ayanamsa');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('aspect_orbs');
        });
    }
};
