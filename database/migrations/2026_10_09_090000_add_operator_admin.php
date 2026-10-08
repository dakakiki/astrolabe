<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8c: the operator's admin.
 *
 * - An admin is a separate account (`users.is_admin`), made only by
 *   `admin:create`, never a member of a practice.
 * - The admin may suspend an astrologer's account; signing in stops at once.
 * - Feedback from the app's "Feedback" button lands in the admin. It goes with
 *   the account that sent it (its author's own words).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
            $table->dateTime('suspended_at')->nullable()->after('is_admin');
            $table->string('suspension_reason', 500)->nullable()->after('suspended_at');
        });

        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 16);
            $table->text('message');
            $table->string('page', 255)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->dateTime('handled_at')->nullable();
            $table->timestamps();

            $table->index(['handled_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'suspended_at', 'suspension_reason']);
        });
    }
};
