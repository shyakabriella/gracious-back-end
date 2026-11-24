<?php

use App\Models\PropertyCategory;
use App\Models\PropertySubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (
        config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database')
            !== ':memory:'
    ) {
        throw new RuntimeException(
            'STOP: Upload tests must use SQLite in-memory.'
        );
    }

    Storage::fake('local');
});

function createUploadSubmission204($test): array
{
    $category = PropertyCategory::create([
        'name' => 'Upload Test Property',
        'slug' => 'upload-test-' . bin2hex(random_bytes(5)),
        'is_active' => true,
    ]);

    $response = $test->postJson(
        '/api/public/property-submissions',
        [
            'submitter_name' => 'Example Property Owner',
            'submitter_phone' => '0788000192',
            'submitter_role' => 'owner',
            'submission_purpose' => 'sale',
            'property_title' => 'Family Property in Kigali',
            'property_description' =>
                'A property submitted with private media.',
            'category_id' => $category->id,
            'asking_price' => 75000000,
            'currency' => 'RWF',
            'province' => 'Kigali City',
            'district' => 'Gasabo',
            'sector' => 'Kacyiru',
            'terms_accepted' => true,
            'authorization_confirmed' => true,
        ]
    )->assertCreated();

    $reference = $response->json(
        'submission_reference'
    );

    $token = $response->json('upload_token');

    $submission = PropertySubmission::query()
        ->where('reference', $reference)
        ->firstOrFail();

    return [$submission, $token];
}

function uploadUrl204(PropertySubmission $submission): string
{
    return "/api/public/property-submissions/{$submission->id}/media";
}

function pngFile204(): UploadedFile
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
        . 'AAAAC0lEQVR42mP8/x8AAwMCAO/a4m0AAAAASUVORK5CYII='
    );

    return UploadedFile::fake()
        ->createWithContent('property.png', $png);
}

function pdfFile204(): UploadedFile
{
    $pdf = "%PDF-1.4\n"
        . "1 0 obj\n"
        . "<< /Type /Catalog >>\n"
        . "endobj\n"
        . "trailer\n"
        . "<< /Root 1 0 R >>\n"
        . "%%EOF\n";

    return UploadedFile::fake()
        ->createWithContent('ownership.pdf', $pdf);
}

test('new submissions receive one private expiring upload token', function () {
    [$submission, $token] =
        createUploadSubmission204($this);

    expect($token)->toBeString();
    expect($token)->toMatch('/\A[a-f0-9]{64}\z/');

    $record = DB::table(
        'property_submission_upload_authorizations'
    )
        ->where(
            'property_submission_id',
            $submission->id
        )
        ->first();

    expect($record)->not->toBeNull();

    expect($record->token_hash)
        ->toBe(hash('sha256', $token));

    expect($record->token_hash)
        ->not->toBe($token);

    expect($submission->fresh()->status)
        ->toBe('pending_review');
});

test('owner can upload a private photo using the token', function () {
    [$submission, $token] =
        createUploadSubmission204($this);

    $this->withHeader(
        'X-Submission-Upload-Token',
        $token
    )
        ->post(uploadUrl204($submission), [
            'kind' => 'photo',
            'file' => pngFile204(),
        ])
        ->assertCreated()
        ->assertJsonPath(
            'media.review_status',
            'pending'
        )
        ->assertJsonPath(
            'media.is_primary',
            true
        )
        ->assertJsonMissingPath(
            'media.file_path'
        )
        ->assertJsonMissingPath(
            'media.sha256'
        );

    $media = DB::table('property_submission_media')
        ->where(
            'property_submission_id',
            $submission->id
        )
        ->first();

    expect($media->storage_disk)->toBe('local');
    expect($media->kind)->toBe('photo');

    expect(
        Storage::disk('local')->exists(
            $media->file_path
        )
    )->toBeTrue();
});

test('upload requires the correct token', function () {
    [$submission] = createUploadSubmission204($this);

    $this->post(uploadUrl204($submission), [
        'kind' => 'photo',
        'file' => pngFile204(),
    ])->assertForbidden();

    $this->withHeader(
        'X-Submission-Upload-Token',
        str_repeat('a', 64)
    )->post(uploadUrl204($submission), [
        'kind' => 'photo',
        'file' => pngFile204(),
    ])->assertForbidden();

    expect(
        DB::table('property_submission_media')->count()
    )->toBe(0);
});

test('one submission token cannot upload to another', function () {
    [$first, $token] =
        createUploadSubmission204($this);

    [$second] = createUploadSubmission204($this);

    $this->withHeader(
        'X-Submission-Upload-Token',
        $token
    )->post(uploadUrl204($second), [
        'kind' => 'photo',
        'file' => pngFile204(),
    ])->assertForbidden();

    expect(
        DB::table('property_submission_media')->count()
    )->toBe(0);
});

test('expired upload tokens are rejected', function () {
    [$submission, $token] =
        createUploadSubmission204($this);

    DB::table(
        'property_submission_upload_authorizations'
    )
        ->where(
            'property_submission_id',
            $submission->id
        )
        ->update([
            'expires_at' => now()->subMinute(),
        ]);

    $this->withHeader(
        'X-Submission-Upload-Token',
        $token
    )->post(uploadUrl204($submission), [
        'kind' => 'photo',
        'file' => pngFile204(),
    ])->assertForbidden();
});

test('uploading stops when staff review starts', function () {
    [$submission, $token] =
        createUploadSubmission204($this);

    $submission->forceFill([
        'status' => 'under_review',
    ])->save();

    $this->withHeader(
        'X-Submission-Upload-Token',
        $token
    )->post(uploadUrl204($submission), [
        'kind' => 'photo',
        'file' => pngFile204(),
    ])->assertForbidden();
});

test('applicant ownership PDF is stored privately', function () {
    [$submission, $token] =
        createUploadSubmission204($this);

    $this->withHeader(
        'X-Submission-Upload-Token',
        $token
    )->post(uploadUrl204($submission), [
        'kind' => 'document',
        'document_type' => 'ownership_title',
        'file' => pdfFile204(),
    ])
        ->assertCreated()
        ->assertJsonPath(
            'media.document_type',
            'ownership_title'
        )
        ->assertJsonPath(
            'media.review_status',
            'pending'
        );

    $media = DB::table('property_submission_media')
        ->first();

    expect($media->kind)->toBe('document');

    expect(
        Storage::disk('local')->exists(
            $media->file_path
        )
    )->toBeTrue();

    // This applicant upload must not bypass
    // independent staff ownership verification.
    expect(
        DB::table(
            'property_submission_verification_checks'
        )->count()
    )->toBe(0);
});

test('a document requires an allowed document type', function () {
    [$submission, $token] =
        createUploadSubmission204($this);

    $this->withHeader(
        'X-Submission-Upload-Token',
        $token
    )->post(uploadUrl204($submission), [
        'kind' => 'document',
        'file' => pdfFile204(),
    ])->assertUnprocessable();

    expect(
        DB::table('property_submission_media')->count()
    )->toBe(0);
});

test('disguised video is rejected', function () {
    [$submission, $token] =
        createUploadSubmission204($this);

    $file = UploadedFile::fake()->createWithContent(
        'fake.mp4',
        'This is not a real video file.'
    );

    $this->withHeader(
        'X-Submission-Upload-Token',
        $token
    )->post(uploadUrl204($submission), [
        'kind' => 'video',
        'file' => $file,
    ])->assertUnprocessable();

    expect(
        DB::table('property_submission_media')->count()
    )->toBe(0);
});

test('disguised PDF is rejected', function () {
    [$submission, $token] =
        createUploadSubmission204($this);

    $file = UploadedFile::fake()->createWithContent(
        'fake.pdf',
        'This is not a real PDF document.'
    );

    $this->withHeader(
        'X-Submission-Upload-Token',
        $token
    )->post(uploadUrl204($submission), [
        'kind' => 'document',
        'document_type' => 'ownership_title',
        'file' => $file,
    ])->assertUnprocessable();

    expect(
        DB::table('property_submission_media')->count()
    )->toBe(0);
});

test('public upload cannot set review or storage fields', function () {
    [$submission, $token] =
        createUploadSubmission204($this);

    $this->withHeader(
        'X-Submission-Upload-Token',
        $token
    )->post(uploadUrl204($submission), [
        'kind' => 'photo',
        'file' => pngFile204(),
        'review_status' => 'approved',
        'is_primary' => true,
        'file_path' => 'public/malicious.jpg',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'review_status',
            'is_primary',
            'file_path',
        ]);

    expect(
        DB::table('property_submission_media')->count()
    )->toBe(0);
});
