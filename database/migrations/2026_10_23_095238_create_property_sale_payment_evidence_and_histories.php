<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_sale_payment_evidences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_sale_payment_id');
            $table->foreign(
                'property_sale_payment_id',
                'sale_payment_evidence_fk'
            )->references('id')
             ->on('property_sale_payments')
             ->restrictOnDelete();

            $table->string('original_name');
            $table->string('disk')->default('local');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(
                'property_sale_payment_id',
                'sale_payment_evidence_lookup_idx'
            );
        });

        Schema::create('property_sale_payment_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_sale_payment_id');
            $table->foreign(
                'property_sale_payment_id',
                'sale_payment_history_fk'
            )->references('id')
             ->on('property_sale_payments')
             ->restrictOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();

            $table->foreignId('changed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(
                'property_sale_payment_id',
                'sale_payment_status_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_sale_payment_status_histories');
        Schema::dropIfExists('property_sale_payment_evidences');
    }
};
