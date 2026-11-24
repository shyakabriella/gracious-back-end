<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'property_submission_verification_checks',
            function (Blueprint $table) {
                $table->id();

                // Submission being verified.
                $table->foreignId('property_submission_id');

                // Staff responsible for the verification.
                $table->foreignId('performed_by')->nullable();

                // Preserve the name for audit purposes.
                $table->string('performed_by_name')->nullable();

                // contact or ownership
                $table->string('check_type', 20);

                // verified, failed or revoked
                $table->string('outcome', 20);

                // Example: phone_call, land_title_review
                $table->string('method', 40);

                // Opaque internal reference only.
                // Do not store document numbers, file paths,
                // passwords or document contents here.
                $table->string(
                    'evidence_reference',
                    120
                )->nullable();

                // Short staff explanation, not document content.
                $table->text('notes')->nullable();

                // Event time, without an update timestamp.
                $table->timestamp('created_at')->useCurrent();

                $table->foreign(
                    'property_submission_id',
                    'psvc_submission_fk'
                )
                    ->references('id')
                    ->on('property_submissions')
                    ->restrictOnDelete();

                $table->foreign(
                    'performed_by',
                    'psvc_staff_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->index(
                    [
                        'property_submission_id',
                        'check_type',
                        'id',
                    ],
                    'psvc_submission_type_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_submission_verification_checks'
        );
    }
};
