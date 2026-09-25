<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Follow-ups and to-dos (docs/spec/02, "Zadaci i follow-up"). A task may
        // belong to a client and, as a follow-up, to one of that client's consultations.
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 8);
            $table->string('status', 8);

            // The due date as entered: a day, optionally a time, in the zone it was
            // entered in. `due_at` is the deadline in UTC — the time given, or the
            // end of the day — so "overdue" is one comparison.
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->dateTime('due_at')->nullable();

            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status', 'due_at']);
            $table->index(['workspace_id', 'client_id', 'status']);
            $table->index(['workspace_id', 'consultation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
