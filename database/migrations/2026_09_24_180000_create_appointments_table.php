<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Time in the calendar (docs/spec/10). The professional record of what
        // happened is the consultation; the two are linked, not merged.
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // UTC, with the IANA zone the time was entered in.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);

            $table->string('status', 16);
            $table->string('location_type', 16);
            $table->string('location_details', 500)->nullable();
            $table->string('booking_source', 16);
            $table->text('notes')->nullable();

            // Cancelling keeps the appointment (docs/spec/10: history is never lost).
            $table->string('cancellation_reason', 500)->nullable();
            $table->dateTime('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'starts_at']);
            $table->index(['workspace_id', 'assigned_user_id', 'starts_at']);
            $table->index(['workspace_id', 'client_id', 'starts_at']);
        });

        Schema::table('consultations', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
        });

        Schema::dropIfExists('appointments');
    }
};
