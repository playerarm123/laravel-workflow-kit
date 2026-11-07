<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            /** unique whatever its case: the use cases check that first, with lower() on every engine; this only backs them up */
            $table->string('name', 60)->unique();
            $table->timestamps();
        });

        Schema::create('media_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            /** null means in no folder; deleting a folder leaves its images in none */
            $table->foreignUuid('folder_id')->nullable()->index()->constrained('media_folders')->nullOnDelete();
            $table->string('name', 150);
            /** null means no alt text */
            $table->string('alt_text', 255)->nullable();
            /** the disk the files were written to, which config may have changed since */
            $table->string('disk', 40);
            $table->string('path');
            $table->string('thumbnail_path');
            $table->string('mime_type', 40);
            /** bytes of the stored original, after it was resized */
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('thumbnail_size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();

            /** the library lists its images newest first */
            $table->index('created_at');
        });

        Schema::create('media_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            /** DeleteMediaItem refuses while a usage remains; the cascade only keeps rows from orphaning */
            $table->foreignUuid('media_item_id')->constrained('media_items')->cascadeOnDelete();
            /** a logical key of what uses the image, such as 'profile', never a class name */
            $table->string('usable_type', 40);
            /** the id of what uses the image; it has no foreign key, because it may be any aggregate */
            $table->uuid('usable_id');
            /** what a person reads to know where the image is used, such as a person's name */
            $table->string('label');
            $table->timestamps();

            /** one row per place: attaching again keeps it (attachUsage upserts on this key) */
            $table->unique(['media_item_id', 'usable_type', 'usable_id']);
            /** every image one thing uses, to detach them */
            $table->index(['usable_type', 'usable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_usages');
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('media_folders');
    }
};
