<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9a: the client portal — access and overview (docs/spec/12).
 *
 * - A portal account is a person who signs in to portal.astrolabe.online with
 *   an emailed link or code; it belongs to no practice.
 * - It reaches a practice only through an accepted invitation: one link per
 *   client record, invited → active → revoked. The same address never joins
 *   client records by itself.
 * - Its sessions live in their own table under their own cookie, never in the
 *   astrologers' `sessions`.
 * - The practice shows its display name, logo and one colour in the portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name', 120)->nullable();
            $table->string('locale', 10)->nullable();
            // Until the person picks one, times are shown in the practice's zone.
            $table->string('timezone', 64)->nullable();
            // The first sign-in: an emailed link or code proves the address.
            $table->dateTime('email_verified_at')->nullable();
            $table->dateTime('last_signed_in_at')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // Empty until the invitation is accepted.
            $table->foreignId('portal_user_id')->nullable()->constrained()->nullOnDelete();
            // Where the invitation went; the client's address may change later.
            $table->string('email');
            $table->string('status', 16);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('invited_at');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            // When the client last opened this practice in the portal (the astrologer sees it).
            $table->dateTime('last_seen_at')->nullable();
            // When the client last looked at what was shared, for "new since your last visit".
            $table->dateTime('shared_seen_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'client_id', 'status']);
            $table->index(['portal_user_id', 'status']);
        });

        Schema::create('portal_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_access_id')->constrained('portal_access')->cascadeOnDelete();
            // SHA-256 of the token; the token itself exists only in the email.
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('used_at')->nullable();
            // A newer invitation to the same client, or the access revoked.
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('portal_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_user_id')->constrained()->cascadeOnDelete();
            // One request gives a link and a six-digit code; using either uses up both.
            $table->char('token_hash', 64)->unique();
            $table->char('code_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at');

            $table->index(['portal_user_id', 'used_at']);
        });

        // The same shape as Laravel's `sessions`, for the portal's own session handler.
        Schema::create('portal_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            // What clients see: the name (otherwise `name`), a logo and one colour.
            $table->string('display_name', 120)->nullable()->after('name');
            $table->string('logo_path')->nullable()->after('display_name');
            $table->char('brand_color', 7)->nullable()->after('logo_path');
        });

        // A client's own actions in the portal are theirs, not an astrologer's.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('portal_user_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('portal_user_id');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'logo_path', 'brand_color']);
        });

        Schema::dropIfExists('portal_sessions');
        Schema::dropIfExists('portal_login_tokens');
        Schema::dropIfExists('portal_invitations');
        Schema::dropIfExists('portal_access');
        Schema::dropIfExists('portal_users');
    }
};
