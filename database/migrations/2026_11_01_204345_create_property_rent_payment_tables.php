<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('property_rent_payments')) {
            Schema::create('property_rent_payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 60)->unique();

            $table->foreignId('property_rental_id')
                ->constrained('property_rentals')
                ->restrictOnDelete();

            $table->string('payment_method', 30);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);

            $table->string('transaction_reference', 150)->nullable();
            $table->date('payment_date');

            $table->string('status', 30)->default('pending');
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('recorded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('verified_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['payment_method', 'transaction_reference'],
                'rent_payment_method_reference_unique'
            );

            $table->index(
                ['property_rental_id', 'status'],
                'rent_payment_rental_status_idx'
            );
        });
        }

        if (!Schema::hasTable('property_rent_payment_allocations')) {
            Schema::create('property_rent_payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_rent_payment_id');
            $table->foreign(
                'property_rent_payment_id',
                'rent_alloc_payment_fk'
            )->references('id')
                ->on('property_rent_payments')
                ->restrictOnDelete();

            $table->foreignId('property_rent_schedule_id');
            $table->foreign(
                'property_rent_schedule_id',
                'rent_alloc_schedule_fk'
            )->references('id')
                ->on('property_rent_schedules')
                ->restrictOnDelete();

            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->unique(
                ['property_rent_payment_id', 'property_rent_schedule_id'],
                'rent_allocation_payment_schedule_unique'
            );
        });
        }

        if (!Schema::hasTable('property_rent_payment_evidences')) {
            Schema::create('property_rent_payment_evidences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_rent_payment_id')
                ->constrained('property_rent_payments')
                ->restrictOnDelete();

            $table->string('original_name', 240);
            $table->string('disk', 30)->default('local');
            $table->string('file_path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();
        });
        }

        if (!Schema::hasTable('property_rent_payment_status_histories')) {
            Schema::create('property_rent_payment_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_rent_payment_id');

            $table->foreign(
                'property_rent_payment_id',
                'rent_payment_history_payment_fk'
            )
                ->references('id')
                ->on('property_rent_payments')
                ->restrictOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('notes')->nullable();

            $table->foreignId('changed_by');
            $table->foreign(
                'changed_by',
                'rent_payment_history_user_fk'
            )->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->timestamps();
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('property_rent_payment_status_histories');
        Schema::dropIfExists('property_rent_payment_evidences');
        Schema::dropIfExists('property_rent_payment_allocations');
        Schema::dropIfExists('property_rent_payments');
    }
};
