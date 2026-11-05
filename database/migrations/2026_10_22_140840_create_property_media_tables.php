<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');

            $table->boolean('is_cover')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['property_id', 'sort_order']);
        });

        Schema::create('property_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');
            $table->string('document_type', 50);
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['property_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_documents');
        Schema::dropIfExists('property_images');
    }
};
