<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertySubmission;
use App\Models\PropertySubmissionEvidenceFile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (
        config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== ':memory:'
    ) {
        throw new RuntimeException(
            'STOP: Evidence tests require in-memory SQLite.'
        );
    }

    // No real private or public evidence files are used.
    Storage::fake('local');

    $this->seed(RolePermissionSeeder::class);

    $this->evidenceCategory188 = PropertyCategory::create([
        'name' => 'Evidence Test House',
        'slug' => 'evidence-test-house-188',
        'is_active' => true,
    ]);
});

function evidenceUser188(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function evidenceSubmission188(
    int $categoryId,
    string $status = 'under_review'
): PropertySubmission {
    $submission = PropertySubmission::create([
        'submitter_name' => 'Evidence Test Landlord',
        'submitter_phone' => '0788000188',
        'submitter_email' => 'owner188@example.com',
        'submitter_role' => 'owner',
        'submission_purpose' => 'sale',
        'property_title' => 'Private Evidence Test Property',
        'property_description' =>
            'Property awaiting evidence and ownership verification.',
        'category_id' => $categoryId,
        'asking_price' => 85000000,
        'province' => 'Kigali City',
        'district' => 'Gasabo',
        'sector' => 'Kacyiru',
    ]);

    $submission->forceFill([
        'status' => $status,
    ])->save();

    return $submission;
}

function evidenceUrl188(
    PropertySubmission $submission
): string {
    return "/api/property-submissions/{$submission->id}/evidence";
}

function evidencePayload188(
    string $type = 'ownership_title'
): array {
    return [
        'title' => 'Land Ownership Evidence',
        'document_type' => $type,
        'file' => UploadedFile::fake()->create(
            'ownership.pdf',
            8,
            'application/pdf'
        ),
    ];
}

function evidenceDownloadUrl188(
    PropertySubmission $submission,
    PropertySubmissionEvidenceFile $evidence
): string {
    return evidenceUrl188($submission)
        . "/{$evidence->id}/download";
}

test('visitor cannot upload or list private evidence', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    $url = evidenceUrl188($submission);

    $this->getJson($url)->assertUnauthorized();

    $this->post(
        $url,
        evidencePayload188(),
        ['Accept' => 'application/json']
    )->assertUnauthorized();

    expect(PropertySubmissionEvidenceFile::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('customers and agents cannot upload or list evidence', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    $url = evidenceUrl188($submission);

    foreach (['customer', 'agent'] as $role) {
        Sanctum::actingAs(evidenceUser188($role));

        $this->getJson($url)->assertForbidden();

        $this->post(
            $url,
            evidencePayload188(),
            ['Accept' => 'application/json']
        )->assertForbidden();
    }

    expect(PropertySubmissionEvidenceFile::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('admin without verification permission cannot upload', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Role::findByName('admin')->syncPermissions([]);

    app(PermissionRegistrar::class)
        ->forgetCachedPermissions();

    $admin = evidenceUser188('admin');

    expect($admin->can('properties.verify'))->toBeFalse();

    Sanctum::actingAs($admin);

    $this->post(
        evidenceUrl188($submission),
        evidencePayload188(),
        ['Accept' => 'application/json']
    )->assertForbidden();

    expect(PropertySubmissionEvidenceFile::count())->toBe(0);
});

test('authorized administrator uploads evidence privately', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    $admin = evidenceUser188('super_admin');

    Sanctum::actingAs($admin);

    $this->post(
        evidenceUrl188($submission),
        evidencePayload188(),
        ['Accept' => 'application/json']
    )
        ->assertCreated()
        ->assertJsonPath(
            'evidence.document_type',
            'ownership_title'
        )
        ->assertJsonMissingPath('evidence.file_path')
        ->assertJsonMissingPath('evidence.storage_disk')
        ->assertJsonMissingPath('evidence.uploaded_by')
        ->assertJsonStructure([
            'evidence' => [
                'id',
                'reference',
                'mime_type',
                'file_size',
                'sha256',
            ],
        ]);

    $evidence = PropertySubmissionEvidenceFile::firstOrFail();

    expect(str_starts_with($evidence->reference, 'EV-'))
        ->toBeTrue();

    expect($evidence->property_submission_id)
        ->toBe($submission->id);

    expect($evidence->uploaded_by)->toBe($admin->id);
    expect($evidence->uploaded_by_name)->toBe($admin->name);
    expect($evidence->storage_disk)->toBe('local');

    expect($evidence->file_path)
        ->toStartWith(
            "property-submissions/{$submission->id}/evidence/"
        );

    Storage::disk('local')
        ->assertExists($evidence->file_path);

    expect(strlen($evidence->sha256))->toBe(64);

    expect(hash_file(
        'sha256',
        Storage::disk('local')->path($evidence->file_path)
    ))->toBe($evidence->sha256);

    expect($evidence->withdrawn_at)->toBeNull();
    expect(Property::count())->toBe(0);
    expect($submission->fresh()->status)->toBe('under_review');
});

test('authorized staff can upload owner authorization', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    $this->post(
        evidenceUrl188($submission),
        evidencePayload188('owner_authorization'),
        ['Accept' => 'application/json']
    )
        ->assertCreated()
        ->assertJsonPath(
            'evidence.document_type',
            'owner_authorization'
        );

    expect(PropertySubmissionEvidenceFile::count())->toBe(1);
});

test('staff can list evidence without revealing private paths', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    $url = evidenceUrl188($submission);

    $this->post(
        $url,
        evidencePayload188(),
        ['Accept' => 'application/json']
    )->assertCreated();

    $evidence = PropertySubmissionEvidenceFile::firstOrFail();

    $this->getJson($url . '?per_page=1')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('per_page', 1)
        ->assertJsonPath(
            'data.0.reference',
            $evidence->reference
        )
        ->assertJsonMissingPath('data.0.file_path')
        ->assertJsonMissingPath('data.0.storage_disk');

    $this->getJson($url . '?per_page=101')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);
});

test('authorized staff can download original evidence', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    $this->post(
        evidenceUrl188($submission),
        evidencePayload188(),
        ['Accept' => 'application/json']
    )->assertCreated();

    $evidence = PropertySubmissionEvidenceFile::firstOrFail();

    $original = Storage::disk('local')
        ->get($evidence->file_path);

    $response = $this->get(
        evidenceDownloadUrl188($submission, $evidence)
    );

    $response->assertOk();
    $response->assertHeader(
        'X-Content-Type-Options',
        'nosniff'
    );

    expect(
        $response->headers->get('Cache-Control')
    )->toContain('no-store');

    expect($response->streamedContent())->toBe($original);

    expect(PropertySubmissionEvidenceFile::count())->toBe(1);
});

test('another submission cannot access the same evidence', function () {
    $first = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    $second = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    $this->post(
        evidenceUrl188($first),
        evidencePayload188(),
        ['Accept' => 'application/json']
    )->assertCreated();

    $evidence = PropertySubmissionEvidenceFile::firstOrFail();

    $this->get(
        evidenceDownloadUrl188($second, $evidence),
        ['Accept' => 'application/json']
    )->assertNotFound();

    expect($evidence->property_submission_id)
        ->toBe($first->id);
});

test('agent cannot download administrator evidence', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    $this->post(
        evidenceUrl188($submission),
        evidencePayload188(),
        ['Accept' => 'application/json']
    )->assertCreated();

    $evidence = PropertySubmissionEvidenceFile::firstOrFail();

    Sanctum::actingAs(evidenceUser188('agent'));

    $this->get(
        evidenceDownloadUrl188($submission, $evidence),
        ['Accept' => 'application/json']
    )->assertForbidden();
});

test('modified evidence cannot be downloaded', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    $this->post(
        evidenceUrl188($submission),
        evidencePayload188(),
        ['Accept' => 'application/json']
    )->assertCreated();

    $evidence = PropertySubmissionEvidenceFile::firstOrFail();

    Storage::disk('local')->put(
        $evidence->file_path,
        'This file has been altered.'
    );

    $this->get(
        evidenceDownloadUrl188($submission, $evidence),
        ['Accept' => 'application/json']
    )->assertStatus(409);
});

test('missing stored evidence cannot be downloaded', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    $this->post(
        evidenceUrl188($submission),
        evidencePayload188(),
        ['Accept' => 'application/json']
    )->assertCreated();

    $evidence = PropertySubmissionEvidenceFile::firstOrFail();

    Storage::disk('local')->delete($evidence->file_path);

    $this->get(
        evidenceDownloadUrl188($submission, $evidence),
        ['Accept' => 'application/json']
    )->assertNotFound();
});

test('invalid file type and oversized file are rejected', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    $url = evidenceUrl188($submission);

    $this->post(
        $url,
        [
            'title' => 'Disallowed Text File',
            'document_type' => 'ownership_title',
            'file' => UploadedFile::fake()->create(
                'document.txt',
                2,
                'text/plain'
            ),
        ],
        ['Accept' => 'application/json']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    $this->post(
        $url,
        [
            'title' => 'Oversized Evidence',
            'document_type' => 'ownership_title',
            'file' => UploadedFile::fake()->create(
                'oversized.pdf',
                10241,
                'application/pdf'
            ),
        ],
        ['Accept' => 'application/json']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    expect(PropertySubmissionEvidenceFile::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('only submissions under review accept evidence', function () {
    Sanctum::actingAs(evidenceUser188('super_admin'));

    foreach (['pending_review', 'rejected'] as $status) {
        $submission = evidenceSubmission188(
            $this->evidenceCategory188->id,
            $status
        );

        $this->post(
            evidenceUrl188($submission),
            evidencePayload188(),
            ['Accept' => 'application/json']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    expect(PropertySubmissionEvidenceFile::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('converted submissions cannot receive more evidence', function () {
    $admin = evidenceUser188('super_admin');

    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    $property = Property::create([
        'reference' => 'GI-EVIDENCE-188',
        'title' => 'Existing Verified Property',
        'slug' => 'existing-verified-property-188',
        'category_id' => $this->evidenceCategory188->id,
        'listing_type' => 'sale',
        'asking_price' => 85000000,
        'created_by' => $admin->id,
    ]);

    $submission->forceFill([
        'converted_property_id' => $property->id,
    ])->save();

    Sanctum::actingAs($admin);

    $this->post(
        evidenceUrl188($submission),
        evidencePayload188(),
        ['Accept' => 'application/json']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(PropertySubmissionEvidenceFile::count())->toBe(0);
});

test('internal evidence fields cannot be injected', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    $this->post(
        evidenceUrl188($submission),
        [
            ...evidencePayload188(),
            'reference' => 'EV-FAKE-188',
            'uploaded_by' => 999,
            'storage_disk' => 'public',
            'file_path' => 'public/ownership.pdf',
            'sha256' => str_repeat('a', 64),
            'is_published' => true,
        ],
        ['Accept' => 'application/json']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'reference',
            'uploaded_by',
            'storage_disk',
            'file_path',
            'sha256',
            'is_published',
        ]);

    expect(PropertySubmissionEvidenceFile::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('maximum ten active documents are allowed', function () {
    $submission = evidenceSubmission188(
        $this->evidenceCategory188->id
    );

    Sanctum::actingAs(evidenceUser188('super_admin'));

    // Create ten metadata records inside the temporary
    // SQLite database to test the active-document limit.
    for ($i = 0; $i < 10; $i++) {
        $record = new PropertySubmissionEvidenceFile();

        $record->forceFill([
            'reference' => "EV-LIMIT-188-{$i}",
            'property_submission_id' => $submission->id,
            'document_type' => 'ownership_title',
            'title' => "Test Evidence {$i}",
            'original_name' => "test-{$i}.pdf",
            'storage_disk' => 'local',
            'file_path' =>
                "property-submissions/{$submission->id}/evidence/test-{$i}.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'sha256' => str_repeat('a', 64),
        ])->save();
    }

    $this->post(
        evidenceUrl188($submission),
        evidencePayload188(),
        ['Accept' => 'application/json']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);

    expect(PropertySubmissionEvidenceFile::count())->toBe(10);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
