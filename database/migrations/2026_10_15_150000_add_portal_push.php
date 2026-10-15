<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Web push in the client PWA (docs/spec/12, "Web push (9c)"): one row per browser
        // that switched push on after signing in. It belongs to the portal account (not to a
        // practice) and to the portal session it was made in: signing out of that session,
        // "Sign out everywhere", the session expiring or the account going removes it.
        // The endpoint is the push service's address for that browser; it is looked up by
        // its SHA-256 (an index on the URL itself would be too long).
        Schema::create('portal_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_user_id')->constrained()->cascadeOnDelete();
            $table->string('portal_session_id')->nullable()->index();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // How the client hears about their appointments: email (the default), push, or both.
        // Push without a device that has it on falls back to email.
        Schema::table('portal_users', function (Blueprint $table) {
            $table->string('notification_channel', 8)->default('email')->after('appointment_reminders');
        });
    }

    public function down(): void
    {
        Schema::table('portal_users', function (Blueprint $table) {
            $table->dropColumn('notification_channel');
        });

        Schema::dropIfExists('portal_push_subscriptions');
    }
};
