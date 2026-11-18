<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notifications in the app (the bell in the header; docs/spec/09, "Obaveštenja"): one row per
        // notice to one person, in the practice it is about (null: about the account). Only kinds,
        // times and ids — names are read when the list is shown, so nothing personal is copied here.
        // They go with the person and with the practice, and after `retention.app_notifications_days`.
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->json('data');
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['user_id', 'workspace_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
