<?php

use App\Models\PropertySubmission;
use App\Models\PropertySubmissionMedia;
use App\Models\PropertySubmissionUploadAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('private submission media tables are available', function () {
    if (
        config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== ':memory:'
    ) {
        throw new RuntimeException(
            'STOP: Tests require in-memory SQLite.'
        );
    }

    expect(
        Schema::hasTable(
            'property_submission_upload_authorizations'
        )
    )->toBeTrue();

    expect(
        Schema::hasTable('property_submission_media')
    )->toBeTrue();

    expect(
        Schema::hasTable(
            'property_submission_media_review_events'
        )
    )->toBeTrue();
});

test('media records have required private storage fields', function () {
    foreach ([
        'property_submission_id',
        'kind',
        'document_type',
        'original_name',
        'storage_disk',
        'file_path',
        'mime_type',
        'file_size',
        'sha256',
        'sort_order',
        'is_primary',
        'review_status',
        'reviewed_by',
        'deleted_at',
    ] as $column) {
        expect(
            Schema::hasColumn(
                'property_submission_media',
                $column
            )
        )->toBeTrue();
    }
});

test('upload authorization supports expiring credentials', function () {
    $submission = PropertySubmission::create([
        'submitter_name' => 'Test Property Owner',
        'submitter_phone' => '0788000192',
        'submitter_role' => 'owner',
        'submission_purpose' => 'sale',
        'property_title' => 'Test Property',
    ]);

    $credential = new PropertySubmissionUploadAuthorization();

    $credential->forceFill([
        'property_submission_id' => $submission->id,
        'token_hash' => hash('sha256', 'private-test-token'),
        'expires_at' => now()->addDay(),
    ])->save();

    expect($credential->isUsable())->toBeTrue();

    expect(
        $credential->toArray()
    )->not->toHaveKey('token_hash');

    $credential->forceFill([
        'revoked_at' => now(),
    ])->save();

    expect($credential->fresh()->isUsable())->toBeFalse();

    expect(
        $credential->submission->id
    )->toBe($submission->id);
});

test('media records belong to the correct submission', function () {
    $submission = PropertySubmission::create([
        'submitter_name' => 'Test Property Owner',
        'submitter_phone' => '0788000192',
        'submitter_role' => 'owner',
        'submission_purpose' => 'sale',
        'property_title' => 'Test Property',
    ]);

    $media = new PropertySubmissionMedia();

    $media->forceFill([
        'property_submission_id' => $submission->id,
        'kind' => 'photo',
        'original_name' => 'house.jpg',
        'storage_disk' => 'local',
        'file_path' => 'private/test/house.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'sha256' => hash('sha256', 'test-photo'),
    ])->save();

    expect($media->submission->id)
        ->toBe($submission->id);

    expect($media->review_status)->toBe('pending');
    expect($media->is_primary)->toBeFalse();

    expect(
        DB::table('property_submission_media')->count()
    )->toBe(1);
});
