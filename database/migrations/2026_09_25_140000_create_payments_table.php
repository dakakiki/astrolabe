<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the consultation costs the client (docs/spec/02, "Plaćanja"): from
        // its service, editable, 0 for no charge, null when no fee was set. Money
        // as an integer in the currency's smallest unit (docs/api-conventions.md).
        Schema::table('consultations', function (Blueprint $table) {
            $table->unsignedBigInteger('fee_amount')->nullable()->after('duration_minutes');
            $table->char('fee_currency', 3)->nullable()->after('fee_amount');
        });

        // Money received from clients — or given back. A consultation's balance
        // and billing status are derived from these rows, never stored. A payment
        // for an appointment is a deposit; it moves to the consultation recorded
        // from that appointment.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 8);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->date('paid_on');
            $table->string('method', 16)->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'paid_on']);
            $table->index(['workspace_id', 'client_id']);
            $table->index(['workspace_id', 'consultation_id']);
            $table->index(['workspace_id', 'appointment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn(['fee_amount', 'fee_currency']);
        });
    }
};
