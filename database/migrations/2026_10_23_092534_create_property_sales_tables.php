<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_sales', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('property_id')
                ->constrained('properties')->restrictOnDelete();

            $table->foreignId('customer_id')
                ->constrained('customers')->restrictOnDelete();

            $table->foreignId('lead_id')->nullable()
                ->constrained('leads')->nullOnDelete();

            $table->foreignId('agent_id')
                ->constrained('users')->restrictOnDelete();

            $table->decimal('asking_price', 18, 2);
            $table->decimal('agreed_price', 18, 2);
            $table->string('currency', 3)->default('RWF');

            $table->string('status')->default('negotiation');

            $table->date('agreement_date')->nullable();
            $table->date('expected_completion_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['property_id', 'status']);
            $table->index(['agent_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('property_sale_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_sale_id')
                ->constrained('property_sales')->restrictOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();

            $table->foreignId('changed_by')
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(
                ['property_sale_id', 'created_at'],
                'sale_status_history_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_sale_status_histories');
        Schema::dropIfExists('property_sales');
    }
};
