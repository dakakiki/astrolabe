<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Portal → Profile → Notifications (docs/spec/12, 18 Nov 2026): email and push per notice,
        // `{kind: {mail, push}}`. Null (and any notice missing) follows `notification_channel`
        // and `appointment_reminders`, which stay for what the account chose before.
        Schema::table('portal_users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('notification_channel');
        });
    }

    public function down(): void
    {
        Schema::table('portal_users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
