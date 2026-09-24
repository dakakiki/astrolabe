<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');

            // Money as an integer in the currency's smallest unit (docs/api-conventions.md).
            $table->unsignedBigInteger('price_amount')->nullable();
            $table->char('currency', 3);

            $table->string('location_type', 16);
            $table->string('color', 16)->nullable();
            $table->boolean('requires_deposit')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'is_active']);
        });

        Schema::create('service_astrology_method', function (Blueprint $table) {
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('astrology_method_id')->constrained()->cascadeOnDelete();

            $table->primary(['service_id', 'astrology_method_id']);
        });

        // A service in use cannot be deleted, only deactivated: past consultations keep it.
        Schema::table('consultations', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('created_by')->constrained()->restrictOnDelete();
            $table->index(['workspace_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'service_id']);
            $table->dropConstrainedForeignId('service_id');
        });

        Schema::dropIfExists('service_astrology_method');
        Schema::dropIfExists('services');
    }
};
