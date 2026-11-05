<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_sale_payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('property_sale_id')
                ->constrained('property_sales')
                ->restrictOnDelete();

            $table->string('payment_type');
            $table->string('payment_method');

            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);

            $table->string('transaction_reference')->nullable();
            $table->date('payment_date');

            $table->string('status')->default('pending');

            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('recorded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('verified_by')->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['property_sale_id', 'status']);
            $table->index(['payment_method', 'transaction_reference'], 'sale_payment_txn_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_sale_payments');
    }
};
