<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->id();

            $table->string('reference')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('description')->nullable();

            $table->foreignId('category_id')
                ->constrained('property_categories')
                ->restrictOnDelete();

            $table->string('listing_type', 20);

            $table->decimal('asking_price', 18, 2);
            $table->string('currency', 3)->default('RWF');
            $table->boolean('is_negotiable')->default(false);

            $table->string('province')->nullable();
            $table->string('district')->nullable();
            $table->string('sector')->nullable();
            $table->string('cell')->nullable();
            $table->string('village')->nullable();
            $table->string('address')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->decimal('land_size_sqm', 12, 2)->nullable();
            $table->decimal('building_size_sqm', 12, 2)->nullable();

            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->unsignedSmallInteger('bathrooms')->nullable();
            $table->unsignedSmallInteger('parking_spaces')->nullable();
            $table->unsignedSmallInteger('floors')->nullable();

            $table->boolean('is_furnished')->default(false);

            $table->string('verification_status', 30)
                ->default('draft');

            $table->string('availability_status', 30)
                ->default('available');

            $table->boolean('is_published')->default(false);

            $table->foreignId('agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['listing_type', 'availability_status']);
            $table->index(['verification_status', 'is_published']);
            $table->index(['district', 'sector']);
            $table->index('asking_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
        Schema::dropIfExists('property_categories');
    }
};
