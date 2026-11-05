<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_sale_completion_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_sale_id');

            $table->foreign(
                'property_sale_id',
                'sale_completion_doc_sale_fk'
            )->references('id')
             ->on('property_sales')
             ->restrictOnDelete();

            $table->string('document_type');
            $table->string('original_name');
            $table->string('disk')->default('local');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');

            $table->foreignId('uploaded_by');

            $table->foreign(
                'uploaded_by',
                'sale_completion_doc_user_fk'
            )->references('id')
             ->on('users')
             ->restrictOnDelete();

            $table->timestamps();

            $table->index(
                ['property_sale_id', 'document_type'],
                'sale_completion_doc_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_sale_completion_documents');
    }
};
