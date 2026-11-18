<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A client's request to move a confirmed appointment (docs/spec/12, "Pomeranje kao
        // zahtev"): while the practice confirms bookings by hand, the move waits for the
        // astrologer. The appointment keeps its time; the proposed one is held as well (UTC).
        Schema::table('appointments', function (Blueprint $table) {
            $table->dateTime('proposed_starts_at')->nullable()->after('declined_at');
            $table->dateTime('proposed_ends_at')->nullable()->after('proposed_starts_at');
            $table->dateTime('proposed_at')->nullable()->after('proposed_ends_at');

            $table->index(['workspace_id', 'assigned_user_id', 'proposed_starts_at'], 'appointments_proposed_index');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_proposed_index');
            $table->dropColumn(['proposed_starts_at', 'proposed_ends_at', 'proposed_at']);
        });
    }
};
