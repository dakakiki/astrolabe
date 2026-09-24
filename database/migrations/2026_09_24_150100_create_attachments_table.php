<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files and links on a client, a consultation or a note (docs/spec/05). Files
 * live in private storage under an internal name; the original name is only
 * metadata (docs/spec/03, "Storage fajlova").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // The client everything here belongs to, also for files on a consultation or a
            // note, so a client's files are one indexed query.
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('attachable_type', 40);
            $table->unsignedBigInteger('attachable_id');

            $table->string('kind', 8);
            // The uploaded file's name, or a link's title.
            $table->string('original_name', 255);
            $table->string('url', 2048)->nullable();

            $table->string('storage_disk', 40)->nullable();
            $table->string('storage_path', 255)->nullable();
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('checksum', 64)->nullable();

            $table->string('visibility', 24);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index(['workspace_id', 'client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
