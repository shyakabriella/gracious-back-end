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

uses(RefreshDatabase::class);

beforeEach(function () {
    if (
        config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== ':memory:'
    ) {
        throw new RuntimeException(
            'STOP: Authorization tests require in-memory SQLite.'
        );
    }

    Storage::fake('local');

    $this->seed(RolePermissionSeeder::class);

    $this->authorizationCategory192 = PropertyCategory::create([
        'name' => 'Authorization Test Property',
        'slug' => 'authorization-test-property-192',
        'is_active' => true,
    ]);
});

function authorizationUser192(): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole('super_admin');

    return $user;
}

function authorizationSubmission192(
    int $categoryId,
    string $role = 'authorized_agent'
): PropertySubmission {
    $submission = PropertySubmission::create([
        'submitter_name' => 'Example Property Agent',
        'submitter_phone' => '0788000192',
        'submitter_role' => $role,
        'owner_name' => $role === 'authorized_agent'
            ? 'Example Registered Owner'
            : null,
        'owner_phone' => '0788000193',
        'submission_purpose' => 'sale',
        'property_title' => 'Kigali Agent Submission',
        'property_description' =>
            'Private property submitted for authorization review.',
        'category_id' => $categoryId,
        'asking_price' => 95000000,
        'province' => 'Kigali City',
        'district' => 'Gasabo',
        'sector' => 'Kacyiru',
    ]);

    $submission->forceFill([
        'status' => 'under_review',
    ])->save();

    return $submission;
}

function authorizationUrl192(
    PropertySubmission $submission
): string {
    return "/api/property-submissions/{$submission->id}/verifications";
}

function authorizationPayload192(
    string $outcome = 'verified'
): array {
    $payload = [
        'check_type' => 'authorization',
        'outcome' => $outcome,
        'method' => 'owner_authorization_review',
        'notes' =>
            'Staff reviewed the owner authorization document.',
    ];

    if ($outcome === 'verified') {
        $payload['evidence_reference'] = 'EV-AUTH-192-001';
    }

    return $payload;
}

function authorizationEvidence192(
    PropertySubmission $submission,
    string $type
): PropertySubmissionEvidenceFile {
    $reference = $type === 'ownership_title'
        ? 'EV-TITLE-192-001'
        : 'EV-AUTH-192-001';

    $contents = "%PDF-1.4\n% Test {$type}\n";

    $path = "property-submissions/{$submission->id}/evidence/{$type}-192.pdf";

    Storage::disk('local')->put($path, $contents);

    $record = new PropertySubmissionEvidenceFile();

    $record->forceFill([
        'reference' => $reference,
        'property_submission_id' => $submission->id,
        'document_type' => $type,
        'title' => 'Test Private Evidence',
        'original_name' => "{$type}.pdf",
        'storage_disk' => 'local',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
    ])->save();

    return $record;
}

function prepareOwnership192(
    $test,
    PropertySubmission $submission
): void {
    $url = authorizationUrl192($submission);

    $test->postJson($url, [
        'check_type' => 'contact',
        'outcome' => 'verified',
        'method' => 'phone_call',
        'notes' => 'Staff contacted the submitting agent.',
    ])->assertCreated();

    authorizationEvidence192(
        $submission,
        'ownership_title'
    );

    $test->postJson($url, [
        'check_type' => 'ownership',
        'outcome' => 'verified',
        'method' => 'land_title_review',
        'evidence_reference' => 'EV-TITLE-192-001',
        'notes' => 'Staff reviewed the property land title.',
    ])->assertCreated();
}

function authorizationChecks192(
    PropertySubmission $submission
) {
    return DB::table(
        'property_submission_verification_checks'
    )
        ->where('property_submission_id', $submission->id)
        ->orderBy('id')
        ->get();
}

test('authorized agent can receive a separate authorization decision', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    $admin = authorizationUser192();

    Sanctum::actingAs($admin);

    prepareOwnership192($this, $submission);

    authorizationEvidence192(
        $submission,
        'owner_authorization'
    );

    $this->postJson(
        authorizationUrl192($submission),
        authorizationPayload192()
    )
        ->assertCreated()
        ->assertJsonPath('check_type', 'authorization')
        ->assertJsonPath('outcome', 'verified');

    $history = authorizationChecks192($submission);

    expect($history)->toHaveCount(3);
    expect($history[0]->check_type)->toBe('contact');
    expect($history[1]->check_type)->toBe('ownership');
    expect($history[2]->check_type)->toBe('authorization');
    expect($history[2]->performed_by)->toBe($admin->id);
    expect($history[2]->evidence_reference)
        ->toBe('EV-AUTH-192-001');

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect($submission->fresh()->converted_property_id)
        ->toBeNull();

    expect(Property::count())->toBe(0);
});

test('owner submissions cannot use agent authorization check', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id,
        'owner'
    );

    Sanctum::actingAs(authorizationUser192());

    $this->postJson(
        authorizationUrl192($submission),
        authorizationPayload192('failed')
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_type']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(0);
});

test('agent authorization requires an identified owner', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    $submission->forceFill([
        'owner_name' => null,
    ])->save();

    Sanctum::actingAs(authorizationUser192());

    $this->postJson(
        authorizationUrl192($submission),
        authorizationPayload192('failed')
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_type']);
});

test('authorization cannot be verified before contact', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    authorizationEvidence192(
        $submission,
        'owner_authorization'
    );

    Sanctum::actingAs(authorizationUser192());

    $this->postJson(
        authorizationUrl192($submission),
        authorizationPayload192()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_type']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(0);
});

test('authorization cannot be verified before ownership', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    Sanctum::actingAs(authorizationUser192());

    $url = authorizationUrl192($submission);

    $this->postJson($url, [
        'check_type' => 'contact',
        'outcome' => 'verified',
        'method' => 'phone_call',
        'notes' => 'Staff contacted the property agent.',
    ])->assertCreated();

    authorizationEvidence192(
        $submission,
        'owner_authorization'
    );

    $this->postJson(
        $url,
        authorizationPayload192()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_type']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(1);
});

test('authorization requires its own evidence reference', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    Sanctum::actingAs(authorizationUser192());

    prepareOwnership192($this, $submission);

    $payload = authorizationPayload192();

    unset($payload['evidence_reference']);

    $this->postJson(
        authorizationUrl192($submission),
        $payload
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_reference']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(2);
});

test('authorization requires authorization review method', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    Sanctum::actingAs(authorizationUser192());

    prepareOwnership192($this, $submission);

    authorizationEvidence192(
        $submission,
        'owner_authorization'
    );

    $payload = authorizationPayload192();

    $payload['method'] = 'land_title_review';

    $this->postJson(
        authorizationUrl192($submission),
        $payload
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['method']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(2);
});

test('ownership title cannot substitute for authorization document', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    Sanctum::actingAs(authorizationUser192());

    prepareOwnership192($this, $submission);

    $payload = authorizationPayload192();

    $payload['evidence_reference'] = 'EV-TITLE-192-001';

    $this->postJson(
        authorizationUrl192($submission),
        $payload
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_reference']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(2);
});

test('authorization evidence from another submission is rejected', function () {
    $first = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    $second = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    authorizationEvidence192(
        $second,
        'owner_authorization'
    );

    Sanctum::actingAs(authorizationUser192());

    prepareOwnership192($this, $first);

    $this->postJson(
        authorizationUrl192($first),
        authorizationPayload192()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_reference']);

    expect(authorizationChecks192($first))
        ->toHaveCount(2);
});

test('invalid authorization evidence is rejected', function (
    string $scenario
) {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    Sanctum::actingAs(authorizationUser192());

    prepareOwnership192($this, $submission);

    if ($scenario !== 'missing_record') {
        $evidence = authorizationEvidence192(
            $submission,
            'owner_authorization'
        );

        if ($scenario === 'withdrawn') {
            $evidence->forceFill([
                'withdrawn_at' => now(),
                'withdrawal_reason' =>
                    'Owner authorization withdrawn.',
            ])->save();
        }

        if ($scenario === 'deleted_file') {
            Storage::disk('local')
                ->delete($evidence->file_path);
        }

        if ($scenario === 'tampered_file') {
            Storage::disk('local')->put(
                $evidence->file_path,
                'Document contents were changed.'
            );
        }

        if ($scenario === 'unsafe_path') {
            $evidence->forceFill([
                'file_path' =>
                    'unrelated/owner_authorization-192.pdf',
            ])->save();
        }

        if ($scenario === 'wrong_disk') {
            $evidence->forceFill([
                'storage_disk' => 'public',
            ])->save();
        }
    }

    $this->postJson(
        authorizationUrl192($submission),
        authorizationPayload192()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_reference']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(2);

    expect(Property::count())->toBe(0);
})->with([
    'missing_record',
    'withdrawn',
    'deleted_file',
    'tampered_file',
    'unsafe_path',
    'wrong_disk',
]);

test('authorization decision can be revoked with history preserved', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    Sanctum::actingAs(authorizationUser192());

    prepareOwnership192($this, $submission);

    authorizationEvidence192(
        $submission,
        'owner_authorization'
    );

    $url = authorizationUrl192($submission);

    $this->postJson(
        $url,
        authorizationPayload192()
    )->assertCreated();

    $this->postJson(
        $url,
        authorizationPayload192('revoked')
    )
        ->assertCreated()
        ->assertJsonPath('outcome', 'revoked');

    $history = authorizationChecks192($submission);

    expect($history)->toHaveCount(4);
    expect($history[2]->outcome)->toBe('verified');
    expect($history[3]->outcome)->toBe('revoked');

    $this->postJson(
        $url,
        authorizationPayload192('revoked')
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(4);
});

test('revoked ownership blocks positive agent authorization', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    Sanctum::actingAs(authorizationUser192());

    prepareOwnership192($this, $submission);

    authorizationEvidence192(
        $submission,
        'owner_authorization'
    );

    $url = authorizationUrl192($submission);

    $this->postJson($url, [
        'check_type' => 'ownership',
        'outcome' => 'revoked',
        'method' => 'land_title_review',
        'notes' => 'Ownership verification revoked after review.',
    ])->assertCreated();

    $this->postJson(
        $url,
        authorizationPayload192()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_type']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(3);
});

test('revoked contact blocks positive agent authorization', function () {
    $submission = authorizationSubmission192(
        $this->authorizationCategory192->id
    );

    Sanctum::actingAs(authorizationUser192());

    prepareOwnership192($this, $submission);

    authorizationEvidence192(
        $submission,
        'owner_authorization'
    );

    $url = authorizationUrl192($submission);

    $this->postJson($url, [
        'check_type' => 'contact',
        'outcome' => 'revoked',
        'method' => 'phone_call',
        'notes' => 'Applicant contact verification revoked.',
    ])->assertCreated();

    $this->postJson(
        $url,
        authorizationPayload192()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_type']);

    expect(authorizationChecks192($submission))
        ->toHaveCount(3);
});
