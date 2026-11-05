<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_owners', function (Blueprint $table) {
            $table->id();

            $table->string('reference')->unique();
            $table->string('owner_type', 20)->default('individual');

            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();

            $table->string('province')->nullable();
            $table->string('district')->nullable();
            $table->string('sector')->nullable();
            $table->string('address')->nullable();

            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['name', 'status']);
            $table->index('phone');
        });

        Schema::create('property_owner', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_id')
                ->constrained('properties')
                ->cascadeOnDelete();

            $table->foreignId('property_owner_id')
                ->constrained('property_owners')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique([
                'property_id',
                'property_owner_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_owner');
        Schema::dropIfExists('property_owners');
    }
};
