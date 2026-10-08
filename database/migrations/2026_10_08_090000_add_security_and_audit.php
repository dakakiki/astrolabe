<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Closed beta (Phase 8a): an account is created only through an invitation the
        // operator sends (`php artisan invitations:send`). Only the token's hash is kept;
        // the link itself exists only in the email.
        Schema::create('registration_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->string('note', 255)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index('email');
        });

        // Who did what and when, for security and the critical operations
        // (docs/spec/06): sign-ins, password and two-factor changes, exports,
        // downloads, deletions, practice settings. Never content or values.
        // Not the client timeline (activity_events), and never rebuilt.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['workspace_id', 'created_at']);
            $table->index(['event', 'created_at']);
        });

        // Optional two-factor authentication (TOTP, Laravel Fortify). Secret and
        // recovery codes are encrypted with the application key.
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->dateTime('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('registration_invitations');
    }
};
