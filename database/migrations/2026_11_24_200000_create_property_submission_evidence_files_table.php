<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'property_submission_evidence_files',
            function (Blueprint $table) {
                $table->id();

                // Server-generated reference, e.g. EV-ULID.
                $table->string('reference', 50)->unique();

                // Evidence belongs to one application.
                $table->foreignId('property_submission_id')
                    ->constrained('property_submissions')
                    ->restrictOnDelete();

                // Preserve uploader information for audits.
                $table->foreignId('uploaded_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->string('uploaded_by_name')->nullable();

                // ownership_title or owner_authorization.
                $table->string('document_type', 40);

                $table->string('title');
                $table->string('original_name');

                // Storage must be private.
                // Future controller will enforce local disk.
                $table->string('storage_disk', 30)
                    ->default('local');

                // Internal path, never expose publicly.
                $table->string('file_path', 512)->unique();

                $table->string('mime_type', 100);
                $table->unsignedBigInteger('file_size');

                // SHA-256 checksum of uploaded file bytes.
                $table->char('sha256', 64);

                // Preserve metadata when evidence is withdrawn.
                $table->timestamp('withdrawn_at')->nullable();

                $table->foreignId('withdrawn_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->string('withdrawn_by_name')
                    ->nullable();

                $table->text('withdrawal_reason')->nullable();

                $table->timestamps();

                $table->index(
                    [
                        'property_submission_id',
                        'document_type',
                        'id',
                    ],
                    'psef_submission_type_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'property_submission_evidence_files'
        );
    }
};
