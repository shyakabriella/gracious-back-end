<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'property_submission_owner_confirmation_events',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId('property_submission_id')
                    ->constrained('property_submissions')
                    ->restrictOnDelete();

                $table->foreignId('reviewer_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                // Preserve staff identity for future audits.
                $table->string('reviewer_name')->nullable();

                // confirmed, denied, revoked
                $table->string('outcome', 20);

                // phone_call or in_person
                $table->string('contact_method', 30);

                // How staff independently found the owner.
                $table->string('contact_source', 40);

                // Identity independently confirmed by staff.
                $table->string('confirmed_owner_name')
                    ->nullable();

                // Do not store a plaintext phone number.
                // The fingerprint will use a keyed HMAC.
                $table->char('contact_fingerprint', 64)
                    ->nullable();

                $table->string('contact_phone_last4', 4)
                    ->nullable();

                // sale, rent or management
                $table->string('confirmed_purpose', 30)
                    ->nullable();

                $table->boolean('owner_identity_confirmed')
                    ->default(false);

                $table->boolean('service_consent_confirmed')
                    ->default(false);

                // Mandatory explanation enforced by controller.
                $table->text('notes');

                // Append-only audit record.
                $table->timestamp('created_at')
                    ->useCurrent();

                $table->index(
                    ['property_submission_id', 'id'],
                    'psoce_submission_id_idx'
                );

                $table->index(
                    ['outcome', 'created_at'],
                    'psoce_outcome_created_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_submission_owner_confirmation_events'
        );
    }
};
