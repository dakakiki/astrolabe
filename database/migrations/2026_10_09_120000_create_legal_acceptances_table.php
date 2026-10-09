<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8c: who accepted which version of the Terms of Service and the Data
 * Processing Agreement, and who saw which privacy policy — when, and from
 * which address. Kept for as long as the account exists (the audit log
 * forgets after twelve months; this is the record of the agreement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 16);
            $table->string('version', 32);
            $table->dateTime('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->unique(['user_id', 'document', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
