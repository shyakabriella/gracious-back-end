<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_submissions', function (Blueprint $table) {
            $table->id();

            // Unique public application reference
            $table->string('reference', 50)->unique();

            // Submission state, separate from property verification
            $table->string('status', 30)
                ->default('pending_review');

            $table->string('source', 30)
                ->default('website');

            // Applicant information
            $table->string('submitter_name');
            $table->string('submitter_phone', 30);
            $table->string('submitter_email')->nullable();
            $table->string('submitter_role', 30);

            // Separate owner details when submitted by an agent
            $table->string('owner_name')->nullable();
            $table->string('owner_phone', 30)->nullable();

            // Requested service: sale, rent or management
            $table->string('submission_purpose', 30);

            // Property details
            $table->string('property_title');
            $table->longText('property_description')->nullable();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('property_categories')
                ->restrictOnDelete();

            $table->decimal('asking_price', 18, 2)->nullable();
            $table->string('currency', 3)->default('RWF');
            $table->boolean('is_negotiable')->default(false);

            // Rwanda address
            $table->string('province')->nullable();
            $table->string('district')->nullable();
            $table->string('sector')->nullable();
            $table->string('cell')->nullable();
            $table->string('village')->nullable();
            $table->string('address')->nullable();

            // Optional map location
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Physical property specifications
            $table->decimal('land_size_sqm', 12, 2)->nullable();
            $table->decimal('building_size_sqm', 12, 2)->nullable();

            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->unsignedSmallInteger('bathrooms')->nullable();
            $table->unsignedSmallInteger('parking_spaces')->nullable();
            $table->unsignedSmallInteger('floors')->nullable();

            $table->boolean('is_furnished')->default(false);

            // Submission declarations
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('authorization_confirmed_at')->nullable();
            $table->timestamp('contact_verified_at')->nullable();

            // Internal staff review
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Official property created after staff approval
            $table->foreignId('converted_property_id')
                ->nullable()
                ->unique()
                ->constrained('properties')
                ->nullOnDelete();

            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            // Review queue and duplicate-detection lookups
            $table->index(['status', 'created_at']);
            $table->index(['submitter_phone', 'created_at']);
            $table->index(['district', 'sector']);
            $table->index(['submission_purpose', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_submissions');
    }
};
