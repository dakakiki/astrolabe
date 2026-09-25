<?php

use App\Models\User;
use App\Support\Notifications\TaskDigest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Email to the astrologer (docs/spec/10, "Notifikacije"). Preferences are
        // personal; null means the defaults (NotificationPreferences). The next
        // morning email is stored as a UTC moment so the scheduler only compares.
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('timezone');
            $table->dateTime('next_digest_at')->nullable()->after('notification_preferences');

            $table->index('next_digest_at');
        });

        // How long before the appointment its reminder goes out (null = none), the
        // moment that works out to on the astrologer's clock, and when it was sent.
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedSmallInteger('reminder_minutes')->nullable()->after('notes');
            $table->dateTime('remind_at')->nullable()->after('reminder_minutes');
            $table->dateTime('reminder_sent_at')->nullable()->after('remind_at');

            // Across workspaces: the scheduler looks for unsent reminders that are due.
            $table->index(['reminder_sent_at', 'remind_at']);
        });

        // "Remind me": the task counts in the morning email on the day it is due.
        Schema::table('tasks', function (Blueprint $table) {
            $table->boolean('remind')->default(true)->after('priority');
        });

        // Existing people start with the default morning email.
        $now = CarbonImmutable::now();
        User::query()->each(function (User $user) use ($now) {
            DB::table('users')->where('id', $user->id)->update(['next_digest_at' => TaskDigest::nextAt($user, $now)]);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('remind');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['reminder_sent_at', 'remind_at']);
            $table->dropColumn(['reminder_minutes', 'remind_at', 'reminder_sent_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['next_digest_at']);
            $table->dropColumn(['notification_preferences', 'next_digest_at']);
        });
    }
};
