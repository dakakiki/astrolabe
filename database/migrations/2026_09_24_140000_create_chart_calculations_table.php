<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calculated charts (docs/spec/05). A cache and a history, never the source of
 * truth: everything here can be recalculated from the birth details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('chart_type', 20);
            $table->char('input_hash', 64);
            $table->decimal('julian_day_ut', 16, 8);
            $table->string('house_system', 32)->nullable();
            $table->string('zodiac_mode', 16);
            $table->string('ayanamsa', 32)->nullable();
            $table->string('time_accuracy', 16);
            $table->json('payload');
            $table->string('engine_name', 60);
            $table->string('engine_version', 40);
            $table->string('ephemeris_version', 255)->nullable();
            $table->string('tzdata_version', 20)->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'chart_type', 'input_hash'], 'chart_calculations_subject_input_unique');
            $table->index(['workspace_id', 'subject_type', 'subject_id'], 'chart_calculations_workspace_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_calculations');
    }
};
