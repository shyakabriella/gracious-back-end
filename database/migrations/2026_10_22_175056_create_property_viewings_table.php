<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_viewings', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('lead_id')
                ->constrained('leads')->restrictOnDelete();

            $table->foreignId('property_id')
                ->constrained('properties')->restrictOnDelete();

            $table->foreignId('agent_id')
                ->constrained('users')->restrictOnDelete();

            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at');

            $table->enum('status', [
                'requested',
                'confirmed',
                'completed',
                'cancelled',
                'no_show',
            ])->default('requested');

            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agent_id', 'scheduled_start_at']);
            $table->index(['property_id', 'scheduled_start_at']);
            $table->index(['status', 'scheduled_start_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_viewings');
    }
};
