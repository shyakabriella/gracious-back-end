<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_rentals', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 60)->unique();

            $table->foreignId('property_id');
            $table->foreign('property_id', 'rental_property_fk')
                ->references('id')->on('properties')
                ->restrictOnDelete();

            $table->foreignId('customer_id');
            $table->foreign('customer_id', 'rental_customer_fk')
                ->references('id')->on('customers')
                ->restrictOnDelete();

            $table->foreignId('agent_id')->nullable();
            $table->foreign('agent_id', 'rental_agent_fk')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->foreignId('created_by');
            $table->foreign('created_by', 'rental_creator_fk')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            $table->decimal('monthly_rent', 18, 2);
            $table->decimal('security_deposit', 18, 2)
                ->default(0);

            $table->string('currency', 3)->default('RWF');

            $table->string('status', 30)->default('draft');

            $table->unsignedTinyInteger('payment_due_day')
                ->default(1);

            $table->dateTime('activated_at')->nullable();
            $table->dateTime('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(
                ['property_id', 'status'],
                'rental_property_status_idx'
            );

            $table->index(
                ['customer_id', 'status'],
                'rental_customer_status_idx'
            );

            $table->index(
                ['agent_id', 'status'],
                'rental_agent_status_idx'
            );
        });

        Schema::create('property_rental_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_rental_id');
            $table->foreign(
                'property_rental_id',
                'rental_history_rental_fk'
            )->references('id')->on('property_rentals')
             ->restrictOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('notes')->nullable();

            $table->foreignId('changed_by');
            $table->foreign(
                'changed_by',
                'rental_history_user_fk'
            )->references('id')->on('users')
             ->restrictOnDelete();

            $table->timestamps();
        });

        Schema::create('property_rent_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_rental_id');
            $table->foreign(
                'property_rental_id',
                'rent_schedule_rental_fk'
            )->references('id')->on('property_rentals')
             ->restrictOnDelete();

            $table->string('reference', 60)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');

            $table->decimal('amount_due', 18, 2);
            $table->string('currency', 3)->default('RWF');

            $table->string('status', 30)->default('pending');

            $table->timestamps();

            $table->unique(
                ['property_rental_id', 'period_start'],
                'rent_schedule_period_unique'
            );

            $table->index(
                ['due_date', 'status'],
                'rent_schedule_due_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_rent_schedules');
        Schema::dropIfExists('property_rental_status_histories');
        Schema::dropIfExists('property_rentals');
    }
};
