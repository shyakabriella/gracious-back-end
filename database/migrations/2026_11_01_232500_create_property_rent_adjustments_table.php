<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_rent_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_rental_id')
                ->constrained('property_rentals')
                ->restrictOnDelete();

            $table->foreignId('property_rent_schedule_id')
                ->constrained('property_rent_schedules')
                ->restrictOnDelete();

            $table->string('reference', 64)->unique();

            $table->enum('type', [
                'early_termination',
                'manual_credit',
                'manual_debit',
            ]);

            $table->decimal('amount', 18, 2);

            $table->string('currency', 3);

            $table->text('reason');

            $table->date('effective_date');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(
                ['property_rental_id', 'type'],
                'rent_adjustments_rental_type_idx'
            );

            $table->index(
                'property_rent_schedule_id',
                'rent_adjustments_schedule_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_rent_adjustments');
    }
};
