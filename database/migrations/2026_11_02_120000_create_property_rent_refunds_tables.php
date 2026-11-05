<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_rent_refunds', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 64)->unique();

            $table->foreignId('property_rental_id')
                ->constrained('property_rentals')
                ->restrictOnDelete();

            $table->foreignId('property_rent_payment_id')
                ->constrained('property_rent_payments')
                ->restrictOnDelete();

            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);

            $table->string('status', 30)
                ->default('pending');

            $table->text('reason');

            $table->text('rejection_reason')->nullable();

            $table->string('refund_method', 30)->nullable();

            $table->string('transaction_reference', 150)
                ->nullable();

            $table->date('refunded_at')->nullable();

            $table->foreignId('requested_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('approved_at')->nullable();

            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['property_rental_id', 'status'],
                'rent_refund_rental_status_idx'
            );

            $table->index(
                'property_rent_payment_id',
                'rent_refund_payment_idx'
            );

            $table->unique(
                ['refund_method', 'transaction_reference'],
                'rent_refund_transaction_unique'
            );
        });

        Schema::create(
            'property_rent_refund_status_histories',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('property_rent_refund_id');

                $table->foreign(
                    'property_rent_refund_id',
                    'rent_refund_history_refund_fk'
                )
                    ->references('id')
                    ->on('property_rent_refunds')
                    ->restrictOnDelete();

                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30);

                $table->text('notes')->nullable();

                $table->foreignId('changed_by')
                    ->constrained('users')
                    ->restrictOnDelete();

                $table->timestamps();

                $table->index(
                    'property_rent_refund_id',
                    'rent_refund_history_refund_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_rent_refund_status_histories'
        );

        Schema::dropIfExists('property_rent_refunds');
    }
};
