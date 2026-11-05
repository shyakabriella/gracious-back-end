<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_viewing_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_viewing_id')
                ->constrained('property_viewings')
                ->restrictOnDelete();

            $table->string('from_status');
            $table->string('to_status');
            $table->text('notes')->nullable();

            $table->foreignId('changed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index([
                'property_viewing_id',
                'created_at',
            ], 'viewing_status_history_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_viewing_status_histories');
    }
};
