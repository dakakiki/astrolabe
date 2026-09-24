<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultations and notes (docs/spec/05). Never a table called "sessions":
 * Laravel owns that name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // The chart as it stood when attached; later corrections to birth data do not change it.
            $table->foreignId('chart_calculation_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title', 150)->nullable();
            // UTC, with the zone it was entered in (docs/spec/06, "Vremenske zone").
            $table->dateTime('starts_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('status', 16);

            $table->text('topics')->nullable();
            // Separate fields, never one text with a formatting convention (docs/spec/02).
            $table->mediumText('internal_notes')->nullable();
            $table->mediumText('client_summary')->nullable();
            $table->mediumText('next_steps')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'starts_at']);
            $table->index(['workspace_id', 'client_id', 'starts_at']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('consultation_astrology_method', function (Blueprint $table) {
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('astrology_method_id')->constrained()->cascadeOnDelete();

            $table->primary(['consultation_id', 'astrology_method_id']);
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150)->nullable();
            $table->mediumText('content');
            $table->string('visibility', 24);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
        Schema::dropIfExists('consultation_astrology_method');
        Schema::dropIfExists('consultations');
    }
};
