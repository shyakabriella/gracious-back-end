<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertySubmission;
use App\Models\PropertySubmissionEvidenceFile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'STOP: Tests must use in-memory SQLite.'
        );
    }

    Storage::fake('local');

    $this->seed(RolePermissionSeeder::class);

    $this->verificationCategory184 = PropertyCategory::create([
        'name' => 'Verification Test House',
        'slug' => 'verification-test-house',
        'is_active' => true,
    ]);
});

function verificationUser184(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function verificationSubmission184(
    int $categoryId,
    string $status = 'under_review'
): PropertySubmission {
    $submission = PropertySubmission::create([
        'submitter_name' => 'Test Landlord',
        'submitter_phone' => '0788000184',
        'submitter_email' => 'landlord184@example.com',
        'submitter_role' => 'owner',
        'submission_purpose' => 'sale',
        'property_title' => 'Kigali Verification Property',
        'property_description' =>
            'A property submitted for independent staff verification.',
        'category_id' => $categoryId,
        'asking_price' => 75000000,
        'province' => 'Kigali City',
        'district' => 'Gasabo',
        'sector' => 'Kacyiru',
    ]);

    if ($status !== 'pending_review') {
        $submission->forceFill([
            'status' => $status,
        ])->save();
    }

    return $submission;
}

function verificationUrl184(PropertySubmission $submission): string
{
    return "/api/property-submissions/{$submission->id}/verifications";
}

function contactPayload184(string $outcome = 'verified'): array
{
    return [
        'check_type' => 'contact',
        'outcome' => $outcome,
        'method' => 'phone_call',
        'notes' => 'Staff contacted the applicant by telephone.',
    ];
}

function ownershipPayload184(
    string $outcome = 'verified'
): array {
    $payload = [
        'check_type' => 'ownership',
        'outcome' => $outcome,
        'method' => 'land_title_review',
        'notes' => 'Staff reviewed the submitted ownership information.',
    ];

    if ($outcome === 'verified') {
        $payload['evidence_reference'] = 'EV-TITLE-184-001';
    }

    return $payload;
}

function checks184(PropertySubmission $submission)
{
    return DB::table(
        'property_submission_verification_checks'
    )
        ->where('property_submission_id', $submission->id)
        ->orderBy('id')
        ->get();
}

/*
 * Create real ownership evidence using Laravel fake
 * private storage and the in-memory test database.
 */
function seedOwnershipEvidence190(
    PropertySubmission $submission
): PropertySubmissionEvidenceFile {
    $contents = "%PDF-1.4\n% Private evidence test\n";

    $path = "property-submissions/{$submission->id}/evidence/ownership-190.pdf";

    Storage::disk('local')->put($path, $contents);

    $evidence = new PropertySubmissionEvidenceFile();

    $evidence->forceFill([
        'reference' => 'EV-TITLE-184-001',
        'property_submission_id' => $submission->id,
        'document_type' => 'ownership_title',
        'title' => 'Test Ownership Title',
        'original_name' => 'ownership-190.pdf',
        'storage_disk' => 'local',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
    ])->save();

    return $evidence;
}

test('visitor cannot write or read verification history', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    $url = verificationUrl184($submission);

    $this->postJson($url, contactPayload184())
        ->assertUnauthorized();

    $this->getJson($url)
        ->assertUnauthorized();

    expect(checks184($submission))->toHaveCount(0);
});

test('customer and agent cannot record verification', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    $url = verificationUrl184($submission);

    foreach (['customer', 'agent'] as $role) {
        Sanctum::actingAs(verificationUser184($role));

        $this->postJson($url, contactPayload184())
            ->assertForbidden();

        $this->getJson($url)
            ->assertForbidden();
    }

    expect(checks184($submission))->toHaveCount(0);
});

test('administrator without verification permission is denied', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Role::findByName('admin')->syncPermissions([]);

    app(PermissionRegistrar::class)
        ->forgetCachedPermissions();

    $admin = verificationUser184('admin');

    expect($admin->can('properties.verify'))->toBeFalse();

    Sanctum::actingAs($admin);

    $this->postJson(
        verificationUrl184($submission),
        contactPayload184()
    )->assertForbidden();

    expect(checks184($submission))->toHaveCount(0);
});

test('authorized administrator can verify contact', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    $admin = verificationUser184('super_admin');

    Sanctum::actingAs($admin);

    $this->postJson(
        verificationUrl184($submission),
        contactPayload184()
    )
        ->assertCreated()
        ->assertJsonPath('check_type', 'contact')
        ->assertJsonPath('outcome', 'verified')
        ->assertJsonStructure([
            'verification_event_id',
            'contact_verified_at',
        ]);

    expect($submission->fresh()->contact_verified_at)
        ->not->toBeNull();

    expect($submission->fresh()->status)
        ->toBe('under_review');

    $events = checks184($submission);

    expect($events)->toHaveCount(1);
    expect($events[0]->performed_by)->toBe($admin->id);
    expect($events[0]->performed_by_name)->toBe($admin->name);
    expect($events[0]->method)->toBe('phone_call');
    expect($events[0]->created_at)->not->toBeNull();

    expect(Property::count())->toBe(0);
});

test('pending and rejected applications cannot be verified', function () {
    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    foreach (['pending_review', 'rejected'] as $status) {
        $submission = verificationSubmission184(
            $this->verificationCategory184->id,
            $status
        );

        $this->postJson(
            verificationUrl184($submission),
            contactPayload184()
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        expect(checks184($submission))->toHaveCount(0);
    }
});

test('verification validates type method and notes', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $url = verificationUrl184($submission);

    $this->postJson($url, [
        'check_type' => 'identity',
        'outcome' => 'verified',
        'method' => 'phone_call',
        'notes' => 'Staff checked the applicant contact.',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_type']);

    $this->postJson($url, [
        ...contactPayload184(),
        'method' => 'land_title_review',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['method']);

    $this->postJson($url, [
        ...contactPayload184(),
        'notes' => 'Short',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['notes']);

    expect(checks184($submission))->toHaveCount(0);
});

test('contact checks cannot inject an evidence reference', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $this->postJson(
        verificationUrl184($submission),
        [
            ...contactPayload184(),
            'evidence_reference' => 'EV-FAKE-184-001',
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_reference']);

    expect(checks184($submission))->toHaveCount(0);
});

test('duplicate contact verification is blocked', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $url = verificationUrl184($submission);

    $this->postJson($url, contactPayload184())
        ->assertCreated();

    $this->postJson($url, contactPayload184())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(checks184($submission))->toHaveCount(1);
});

test('contact verification can be revoked with history retained', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $url = verificationUrl184($submission);

    $this->postJson($url, contactPayload184())
        ->assertCreated();

    $this->postJson(
        $url,
        contactPayload184('revoked')
    )
        ->assertCreated()
        ->assertJsonPath('outcome', 'revoked');

    expect($submission->fresh()->contact_verified_at)
        ->toBeNull();

    $events = checks184($submission);

    expect($events)->toHaveCount(2);
    expect($events[0]->outcome)->toBe('verified');
    expect($events[1]->outcome)->toBe('revoked');

    $this->postJson(
        $url,
        contactPayload184('revoked')
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(checks184($submission))->toHaveCount(2);
});

test('verified contact must be revoked before marking failed', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $url = verificationUrl184($submission);

    $this->postJson($url, contactPayload184())
        ->assertCreated();

    $this->postJson(
        $url,
        contactPayload184('failed')
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(checks184($submission))->toHaveCount(1);
});

test('failed contact may later be successfully verified', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $url = verificationUrl184($submission);

    $this->postJson(
        $url,
        contactPayload184('failed')
    )->assertCreated();

    expect($submission->fresh()->contact_verified_at)
        ->toBeNull();

    $this->postJson(
        $url,
        contactPayload184('verified')
    )->assertCreated();

    expect($submission->fresh()->contact_verified_at)
        ->not->toBeNull();

    expect(checks184($submission))->toHaveCount(2);
});

test('ownership verification requires verified contact', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $this->postJson(
        verificationUrl184($submission),
        ownershipPayload184()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_type']);

    expect(checks184($submission))->toHaveCount(0);
});

test('ownership verification requires a valid evidence reference', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $url = verificationUrl184($submission);

    $this->postJson($url, contactPayload184())
        ->assertCreated();

    $withoutEvidence = ownershipPayload184();

    unset($withoutEvidence['evidence_reference']);

    $this->postJson($url, $withoutEvidence)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_reference']);

    $this->postJson($url, [
        ...ownershipPayload184(),
        'evidence_reference' => 'NOT-VALID',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_reference']);

    expect(checks184($submission))->toHaveCount(1);
});

test('administrator can record ownership review and audit trail', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    $admin = verificationUser184('super_admin');

    Sanctum::actingAs($admin);

    $url = verificationUrl184($submission);

    $this->postJson($url, contactPayload184())
        ->assertCreated();

    seedOwnershipEvidence190($submission);

    $this->postJson($url, ownershipPayload184())
        ->assertCreated()
        ->assertJsonPath('check_type', 'ownership')
        ->assertJsonPath('outcome', 'verified');

    $events = checks184($submission);

    expect($events)->toHaveCount(2);
    expect($events[1]->check_type)->toBe('ownership');
    expect($events[1]->outcome)->toBe('verified');
    expect($events[1]->method)->toBe('land_title_review');
    expect($events[1]->performed_by)->toBe($admin->id);
    expect($events[1]->evidence_reference)
        ->toBe('EV-TITLE-184-001');

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect($submission->fresh()->converted_property_id)
        ->toBeNull();

    expect(Property::count())->toBe(0);
});

test('ownership verification can be revoked without erasing history', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $url = verificationUrl184($submission);

    $this->postJson($url, contactPayload184())
        ->assertCreated();

    seedOwnershipEvidence190($submission);

    $this->postJson($url, ownershipPayload184())
        ->assertCreated();

    $this->postJson(
        $url,
        ownershipPayload184('revoked')
    )
        ->assertCreated()
        ->assertJsonPath('outcome', 'revoked');

    $events = checks184($submission);

    expect($events)->toHaveCount(3);
    expect($events[1]->outcome)->toBe('verified');
    expect($events[2]->outcome)->toBe('revoked');

    $this->postJson(
        $url,
        ownershipPayload184('revoked')
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(checks184($submission))->toHaveCount(3);
});

test('revoked contact prevents ownership verification', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $url = verificationUrl184($submission);

    $this->postJson($url, contactPayload184())
        ->assertCreated();

    $this->postJson(
        $url,
        contactPayload184('revoked')
    )->assertCreated();

    $this->postJson($url, ownershipPayload184())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_type']);

    expect(checks184($submission))->toHaveCount(2);
});

test('converted submissions cannot receive new verification', function () {
    $admin = verificationUser184('super_admin');

    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    $property = Property::create([
        'reference' => 'GI-VERIFY-184',
        'title' => 'Existing Private Property',
        'slug' => 'existing-private-property-184',
        'category_id' => $this->verificationCategory184->id,
        'listing_type' => 'sale',
        'asking_price' => 75000000,
        'created_by' => $admin->id,
    ]);

    $submission->forceFill([
        'converted_property_id' => $property->id,
    ])->save();

    Sanctum::actingAs($admin);

    $this->postJson(
        verificationUrl184($submission),
        contactPayload184()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(checks184($submission))->toHaveCount(0);
});

test('internal verification fields cannot be injected', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $this->postJson(
        verificationUrl184($submission),
        [
            ...contactPayload184(),
            'status' => 'approved',
            'performed_by' => 999,
            'performed_by_name' => 'Fake Administrator',
            'contact_verified_at' => '2026-01-01',
            'reviewed_by' => 999,
            'converted_property_id' => 1,
            'is_published' => true,
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'status',
            'performed_by',
            'performed_by_name',
            'contact_verified_at',
            'reviewed_by',
            'converted_property_id',
            'is_published',
        ]);

    expect(checks184($submission))->toHaveCount(0);

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect(Property::count())->toBe(0);
});

test('authorized staff can read paginated verification history', function () {
    $submission = verificationSubmission184(
        $this->verificationCategory184->id
    );

    Sanctum::actingAs(
        verificationUser184('super_admin')
    );

    $url = verificationUrl184($submission);

    $this->postJson($url, contactPayload184())
        ->assertCreated();

    seedOwnershipEvidence190($submission);

    $this->postJson($url, ownershipPayload184())
        ->assertCreated();

    $this->getJson($url . '?per_page=1')
        ->assertOk()
        ->assertJsonPath('per_page', 1)
        ->assertJsonPath('total', 2)
        ->assertJsonPath('data.0.check_type', 'ownership')
        ->assertJsonPath(
            'data.0.evidence_reference',
            'EV-TITLE-184-001'
        )
        ->assertJsonMissingPath('data.0.submitter_phone');

    $this->getJson($url . '?per_page=101')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);
});

test(
    'invalid ownership evidence is rejected without creating a decision',
    function (string $scenario) {
        $submission = verificationSubmission184(
            $this->verificationCategory184->id
        );

        $evidence = null;

        if ($scenario === 'another_submission') {
            $other = verificationSubmission184(
                $this->verificationCategory184->id
            );

            seedOwnershipEvidence190($other);
        } elseif ($scenario !== 'nonexistent') {
            $evidence = seedOwnershipEvidence190($submission);
        }

        if ($scenario === 'withdrawn') {
            $evidence->forceFill([
                'withdrawn_at' => now(),
                'withdrawal_reason' =>
                    'Document withdrawn after review.',
            ])->save();
        }

        if ($scenario === 'missing_file') {
            Storage::disk('local')
                ->delete($evidence->file_path);
        }

        if ($scenario === 'tampered_file') {
            Storage::disk('local')->put(
                $evidence->file_path,
                'Altered ownership document contents.'
            );
        }

        if ($scenario === 'unsafe_path') {
            $evidence->forceFill([
                'file_path' =>
                    'unrelated-folder/ownership-190.pdf',
            ])->save();
        }

        if ($scenario === 'wrong_document_type') {
            $evidence->forceFill([
                'document_type' => 'owner_authorization',
            ])->save();
        }

        Sanctum::actingAs(
            verificationUser184('super_admin')
        );

        $url = verificationUrl184($submission);

        $this->postJson(
            $url,
            contactPayload184()
        )->assertCreated();

        $this->postJson(
            $url,
            ownershipPayload184()
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'evidence_reference',
            ]);

        expect(checks184($submission))->toHaveCount(1);

        expect($submission->fresh()->status)
            ->toBe('under_review');

        expect(Property::count())->toBe(0);
    }
)->with([
    'nonexistent',
    'another_submission',
    'withdrawn',
    'missing_file',
    'tampered_file',
    'unsafe_path',
    'wrong_document_type',
]);

test(
    'authorization review cannot substitute for ownership review',
    function () {
        $submission = verificationSubmission184(
            $this->verificationCategory184->id
        );

        seedOwnershipEvidence190($submission);

        Sanctum::actingAs(
            verificationUser184('super_admin')
        );

        $url = verificationUrl184($submission);

        $this->postJson(
            $url,
            contactPayload184()
        )->assertCreated();

        $payload = ownershipPayload184();

        $payload['method'] = 'owner_authorization_review';

        $this->postJson($url, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);

        expect(checks184($submission))->toHaveCount(1);

        expect(Property::count())->toBe(0);
    }
);
