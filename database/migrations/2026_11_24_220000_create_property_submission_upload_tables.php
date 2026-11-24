<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Public upload access is issued after
         * a valid property submission is created.
         *
         * Only a hash of the upload token is stored.
         */
        Schema::create(
            'property_submission_upload_authorizations',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger(
                    'property_submission_id'
                )->unique('psua_submission_uq');

                $table->string('token_hash', 64)
                    ->unique('psua_token_hash_uq');

                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();

                $table->timestamps();

                $table->foreign(
                    'property_submission_id',
                    'psua_submission_fk'
                )
                    ->references('id')
                    ->on('property_submissions')
                    ->restrictOnDelete();

                $table->index(
                    ['expires_at', 'revoked_at'],
                    'psua_expiry_idx'
                );
            }
        );

        /*
         * Photos, videos and applicant ownership
         * documents remain private by default.
         */
        Schema::create(
            'property_submission_media',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger(
                    'property_submission_id'
                );

                // photo | video | document
                $table->string('kind', 20);

                // ownership_title | owner_authorization | other
                $table->string(
                    'document_type',
                    40
                )->nullable();

                $table->string('original_name');
                $table->string('storage_disk', 30)
                    ->default('local');

                $table->string('file_path', 512);
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('file_size');
                $table->string('sha256', 64);

                $table->unsignedInteger('sort_order')
                    ->default(0);

                $table->boolean('is_primary')
                    ->default(false);

                // pending | approved | rejected
                $table->string('review_status', 20)
                    ->default('pending');

                $table->unsignedBigInteger(
                    'reviewed_by'
                )->nullable();

                $table->string(
                    'reviewer_name'
                )->nullable();

                $table->timestamp(
                    'reviewed_at'
                )->nullable();

                $table->text(
                    'review_notes'
                )->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->foreign(
                    'property_submission_id',
                    'psm_submission_fk'
                )
                    ->references('id')
                    ->on('property_submissions')
                    ->restrictOnDelete();

                $table->foreign(
                    'reviewed_by',
                    'psm_reviewer_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->index(
                    [
                        'property_submission_id',
                        'kind',
                        'review_status'
                    ],
                    'psm_kind_status_idx'
                );

                $table->index(
                    [
                        'property_submission_id',
                        'deleted_at'
                    ],
                    'psm_active_idx'
                );
            }
        );

        /*
         * Append-only review decisions.
         * Later decisions never erase the earlier ones.
         */
        Schema::create(
            'property_submission_media_review_events',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger(
                    'property_submission_media_id'
                );

                $table->unsignedBigInteger(
                    'reviewer_id'
                )->nullable();

                $table->string(
                    'reviewer_name'
                )->nullable();

                $table->string('decision', 20);
                $table->text('reason')->nullable();

                $table->timestamp(
                    'created_at'
                )->useCurrent();

                $table->foreign(
                    'property_submission_media_id',
                    'psmre_media_fk'
                )
                    ->references('id')
                    ->on('property_submission_media')
                    ->restrictOnDelete();

                $table->foreign(
                    'reviewer_id',
                    'psmre_reviewer_fk'
                )
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->index(
                    [
                        'property_submission_media_id',
                        'created_at'
                    ],
                    'psmre_media_created_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_submission_media_review_events'
        );

        Schema::dropIfExists(
            'property_submission_media'
        );

        Schema::dropIfExists(
            'property_submission_upload_authorizations'
        );
    }
};
