<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client timeline (docs/spec/05). A projection, never the only home of
 * business data: `php artisan activity:rebuild` recreates it from the tables
 * it mirrors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('event_type', 40);
            $table->dateTime('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Copied from a note or a file, so the timeline hides what its viewer may not see.
            $table->string('visibility', 24)->nullable();
            $table->string('summary', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'client_id', 'occurred_at'], 'activity_events_timeline_index');
            $table->index(['subject_type', 'subject_id', 'event_type'], 'activity_events_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
