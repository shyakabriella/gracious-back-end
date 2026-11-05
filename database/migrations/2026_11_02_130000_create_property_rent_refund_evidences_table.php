<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'property_rent_refund_evidences',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('property_rent_refund_id');

                $table->foreign(
                    'property_rent_refund_id',
                    'rent_refund_evidence_refund_fk'
                )
                    ->references('id')
                    ->on('property_rent_refunds')
                    ->restrictOnDelete();

                $table->string('original_name', 240);
                $table->string('disk', 30)->default('local');
                $table->string('file_path', 500);
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('file_size');

                $table->string('sha256', 64);

                $table->foreignId('uploaded_by');

                $table->foreign(
                    'uploaded_by',
                    'rent_refund_evidence_uploader_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();

                $table->foreignId('verified_by')->nullable();

                $table->foreign(
                    'verified_by',
                    'rent_refund_evidence_verifier_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->dateTime('verified_at')->nullable();

                $table->timestamps();

                $table->index(
                    'property_rent_refund_id',
                    'rent_refund_evidence_refund_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_rent_refund_evidences'
        );
    }
};
