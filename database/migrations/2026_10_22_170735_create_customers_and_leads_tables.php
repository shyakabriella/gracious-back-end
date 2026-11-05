<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->enum('customer_type', [
                'buyer', 'seller', 'tenant', 'landlord', 'investor',
            ])->default('buyer');
            $table->string('preferred_location')->nullable();
            $table->decimal('budget_min', 18, 2)->nullable();
            $table->decimal('budget_max', 18, 2)->nullable();
            $table->string('currency', 3)->default('RWF');
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive'])
                ->default('active');
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['name', 'status']);
            $table->index('phone');
            $table->index('email');
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('customer_id')
                ->constrained('customers')->restrictOnDelete();
            $table->foreignId('property_id')->nullable()
                ->constrained('properties')->nullOnDelete();
            $table->enum('lead_type', [
                'buy', 'sell', 'rent', 'let', 'consultation',
            ]);
            $table->enum('source', [
                'website', 'phone', 'walk_in', 'referral', 'social_media', 'staff',
            ])->default('staff');
            $table->enum('status', [
                'new', 'contacted', 'qualified', 'viewing',
                'negotiating', 'won', 'lost',
            ])->default('new');
            $table->enum('priority', ['low', 'medium', 'high'])
                ->default('medium');
            $table->string('subject')->nullable();
            $table->text('message')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('assigned_agent_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority']);
            $table->index(['assigned_agent_id', 'status']);
            $table->index('next_follow_up_at');
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')
                ->constrained('leads')->cascadeOnDelete();
            $table->enum('activity_type', [
                'note', 'call', 'email', 'meeting',
                'follow_up', 'status_change', 'assignment',
            ]);
            $table->text('description');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'activity_type']);
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('customers');
    }
};
