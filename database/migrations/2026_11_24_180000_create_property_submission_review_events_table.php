<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'property_submission_review_events',
            function (Blueprint $table) {
                $table->id();

                // A review event belongs to one submission.
                $table->foreignId('property_submission_id');

                // Staff member responsible for the action.
                $table->foreignId('reviewer_id')->nullable();

                // Preserve the staff name for audit history.
                $table->string('reviewer_name')->nullable();

                // Example: review_started, approved, rejected.
                $table->string('action', 40);

                // Status before and after this action.
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();

                // Reason or explanation supplied by staff.
                $table->text('reason')->nullable();

                // Event creation time; no update timestamp.
                $table->timestamp('created_at')->useCurrent();

                $table->foreign(
                    'property_submission_id',
                    'psre_submission_fk'
                )
                    ->references('id')
                    ->on('property_submissions')
                    ->restrictOnDelete();

                $table->foreign(
                    'reviewer_id',
                    'psre_reviewer_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->index(
                    ['property_submission_id', 'created_at'],
                    'psre_submission_created_idx'
                );

                $table->index(
                    ['action', 'created_at'],
                    'psre_action_created_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_submission_review_events'
        );
    }
};
