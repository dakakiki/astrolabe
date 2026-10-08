<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8b, the data lifecycle (docs/spec/06, "Pravna priprema"):
 *
 * - a payment outlives its client: deleting a client for good keeps the money
 *   received (amount, currency, day, method) without saying who paid;
 * - a practice can be scheduled for deletion and cancelled until the day comes;
 * - practice exports are built in the background and kept for a few days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dateTime('deletion_requested_at')->nullable()->after('transit_orbs');
            $table->foreignId('deletion_requested_by')->nullable()->after('deletion_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('deletes_at')->nullable()->after('deletion_requested_by')->index();
        });

        Schema::create('workspace_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->string('disk', 40)->nullable();
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('counts')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_exports');

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deletion_requested_by');
            $table->dropColumn(['deletion_requested_at', 'deletes_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }
};
