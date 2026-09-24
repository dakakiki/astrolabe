<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A partner, child, parent … of a client, without an account (docs/spec/02).
        Schema::create('related_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();

            // Set when the person became a client of their own; the row is then soft-deleted.
            $table->foreignId('converted_client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        // The same structure as client_birth_details, so a person's data can
        // move to a client unchanged — the frozen place included.
        Schema::create('related_person_birth_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_person_id')->unique()->constrained('related_people')->cascadeOnDelete();

            $table->date('birth_date')->nullable();
            $table->time('birth_time')->nullable();
            $table->string('time_accuracy', 16);

            $table->string('birth_place', 255)->nullable();
            $table->char('birth_country_code', 2)->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->string('birth_timezone', 64)->nullable();
            $table->unsignedInteger('place_id')->nullable();
            $table->string('geocode_source', 16)->nullable();

            $table->string('data_source', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // A link from a client to another client or to a related person; exactly
        // one of the two is set. The type says what the other party is to the client.
        Schema::create('client_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('related_person_id')->nullable()->constrained('related_people')->cascadeOnDelete();
            $table->string('relationship_type', 24);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'related_client_id']);
            $table->unique(['client_id', 'related_person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_relationships');
        Schema::dropIfExists('related_person_birth_details');
        Schema::dropIfExists('related_people');
    }
};
