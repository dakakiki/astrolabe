<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('default_locale', 10);
            $table->string('timezone', 64);
            $table->char('default_currency', 3);

            // Chart defaults (docs/spec/05). Aspect orbs arrive with the full chart in Phase 5.
            $table->string('default_house_system', 32);
            $table->string('default_zodiac_mode', 16);
            $table->string('default_ayanamsa', 32)->nullable();

            $table->timestamps();
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('status', 16);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->default('en')->after('password');
            $table->string('timezone', 64)->default('UTC')->after('locale');
            $table->foreignId('current_workspace_id')->nullable()->after('timezone')
                ->constrained('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_workspace_id');
            $table->dropColumn(['locale', 'timezone']);
        });

        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }
};
