<?php

use App\Support\Notifications\ClientReminders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An astrologer's working week (docs/spec/12, "Zakazivanje (9b)"): one row per
        // stretch of a weekday (several a day are fine), as wall-clock times in the zone
        // the week was entered in. "24:00" ends a stretch at midnight.
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1 = Monday … 7 = Sunday (ISO)
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone', 64);
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'weekday']);
        });

        // Time off (`unavailable`) or extra time (`available`) on top of the week, in UTC
        // with the zone it was entered in; whole days keep `all_day` so they read back as days.
        Schema::create('availability_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone', 64);
            $table->boolean('all_day')->default(false);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'ends_at']);
        });

        // How clients book in the portal, one row per practice (created when first saved;
        // until then the defaults apply). Hours of null = the client may not do it.
        Schema::create('booking_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('min_notice_minutes')->default(1440);
            $table->unsignedSmallInteger('max_days_ahead')->default(60);
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(15);
            $table->unsignedSmallInteger('slot_step_minutes')->default(30);
            $table->boolean('requires_confirmation')->default(false);
            $table->unsignedSmallInteger('client_reschedule_until_hours')->nullable()->default(24);
            $table->unsignedSmallInteger('client_cancel_until_hours')->nullable()->default(24);
            $table->text('client_note')->nullable();
            $table->timestamps();
        });

        // A service the client may book in the portal; off until the astrologer says so.
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('bookable_in_portal')->default(false)->after('requires_deposit');
        });

        Schema::table('appointments', function (Blueprint $table) {
            // Who booked it in the portal, and the message they left for the astrologer.
            $table->foreignId('portal_user_id')->nullable()->after('created_by')->constrained('portal_users')->nullOnDelete();
            $table->text('client_note')->nullable()->after('notes');

            // Who cancelled (`practice` or `client`) and, for a request the practice
            // turned down, when — its reason is then written for the client.
            $table->string('cancelled_by', 16)->nullable()->after('cancelled_at');
            $table->dateTime('declined_at')->nullable()->after('cancelled_by');

            // The client's own reminder (a day before), sent only while they can use the portal.
            $table->dateTime('client_remind_at')->nullable()->after('reminder_sent_at');
            $table->dateTime('client_reminder_sent_at')->nullable()->after('client_remind_at');

            $table->index(['client_reminder_sent_at', 'client_remind_at']);
        });

        // Appointments already in the calendar get their client's reminder too, as if
        // saved now (only rows saved from here on are planned by the model).
        ClientReminders::backfill(CarbonImmutable::now());

        // The client's choice in Portal → Profile: an email a day before each appointment.
        Schema::table('portal_users', function (Blueprint $table) {
            $table->boolean('appointment_reminders')->default(true)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('portal_users', function (Blueprint $table) {
            $table->dropColumn('appointment_reminders');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['client_reminder_sent_at', 'client_remind_at']);
            $table->dropConstrainedForeignId('portal_user_id');
            $table->dropColumn(['client_note', 'cancelled_by', 'declined_at', 'client_remind_at', 'client_reminder_sent_at']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('bookable_in_portal');
        });

        Schema::dropIfExists('booking_policies');
        Schema::dropIfExists('availability_exceptions');
        Schema::dropIfExists('availability_rules');
    }
};
