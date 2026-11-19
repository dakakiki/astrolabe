<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin → Notifications (docs/spec/09, "Obaveštenja — tekstovi"): the operator's own wording
        // of a notification's text, one row per translation key and language, laid over the text in
        // the code (OverridableTranslator). Only the keys of the notification catalogue are taken.
        Schema::create('message_texts', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 10);
            $table->string('key', 191);
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['locale', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_texts');
    }
};
