<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('preferred_locale', 10)->nullable();
            $table->string('status', 16);
            $table->text('internal_notes')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'last_name', 'first_name']);
            $table->index(['workspace_id', 'last_activity_at']);
        });

        Schema::create('client_birth_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();

            // As entered: local date and time, never reduced to UTC (docs/spec/05).
            $table->date('birth_date')->nullable();
            $table->time('birth_time')->nullable();
            $table->string('time_accuracy', 16);

            // Frozen at entry: copied from the gazetteer or typed in by hand, never
            // resolved again at calculation time (docs/spec/02).
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

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 20)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('client_tag', function (Blueprint $table) {
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['client_id', 'tag_id']);
        });

        Schema::create('client_astrology_method', function (Blueprint $table) {
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('astrology_method_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('notes', 255)->nullable();

            $table->primary(['client_id', 'astrology_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_astrology_method');
        Schema::dropIfExists('client_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('client_birth_details');
        Schema::dropIfExists('clients');
    }
};
