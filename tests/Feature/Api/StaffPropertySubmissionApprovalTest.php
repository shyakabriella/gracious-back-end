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
            'STOP: Approval tests require in-memory SQLite.'
        );
    }

    Storage::fake('local');

    config()->set(
        'app.key',
        'base64:' . base64_encode(str_repeat('R', 32))
    );

    $this->seed(RolePermissionSeeder::class);

    $this->approvalCategory198 = PropertyCategory::create([
        'name' => 'Approval Test Category',
        'slug' => 'approval-test-category-198',
        'is_active' => true,
    ]);

});

function approvalUser198(string $role = 'super_admin'): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function approvalSubmission198(
    int $categoryId,
    string $role = 'owner',
    string $purpose = 'sale'
): PropertySubmission {
    $submission = PropertySubmission::create([
        'submitter_name' => 'Example Applicant',
        'submitter_phone' => '0788000192',
        'submitter_email' => 'approval198@example.com',
        'submitter_role' => $role,
        'owner_name' => $role === 'authorized_agent'
            ? 'Example Registered Owner'
            : null,
        'owner_phone' => '0788000193',
        'submission_purpose' => $purpose,
        'property_title' => 'Kigali Approval Test Property',
        'property_description' =>
            'A private application for protected property approval.',
        'category_id' => $categoryId,
        'asking_price' => $purpose === 'management'
            ? null
            : 85000000,
        'province' => 'Kigali City',
        'district' => 'Gasabo',
        'sector' => 'Kacyiru',
    ]);

    $submission->forceFill([
        'status' => 'under_review',
        'terms_accepted_at' => now(),
        'authorization_confirmed_at' => now(),
    ])->save();

    return $submission;
}

function approvalUrl198(PropertySubmission $submission): string
{
    return "/api/property-submissions/{$submission->id}/approve";
}

function approvalPayload198(): array
{
    return [
        'reason' =>
            'Ownership and applicant verification completed successfully.',
    ];
}

function approvalEvidence198(
    PropertySubmission $submission,
    string $type
): PropertySubmissionEvidenceFile {
    $label = $type === 'ownership_title'
        ? 'TITLE'
        : 'AUTH';

    $contents = "%PDF-1.4\n% {$type} private test\n";

    $path =
        "property-submissions/{$submission->id}/evidence/{$type}-198.pdf";

    Storage::disk('local')->put($path, $contents);

    $record = new PropertySubmissionEvidenceFile();

    $record->forceFill([
        'reference' => "EV-{$label}-198-{$submission->id}",
        'property_submission_id' => $submission->id,
        'document_type' => $type,
        'title' => 'Private Approval Evidence',
        'original_name' => "{$type}.pdf",
        'storage_disk' => 'local',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
    ])->save();

    return $record;
}

function prepareApproval198(
    $test,
    PropertySubmission $submission,
    bool $withAgentConfirmation = true
): void {
    $url =
        "/api/property-submissions/{$submission->id}/verifications";

    $test->postJson($url, [
        'check_type' => 'contact',
        'outcome' => 'verified',
        'method' => 'phone_call',
        'notes' => 'Staff verified the applicant contact details.',
    ])->assertCreated();

    $title = approvalEvidence198(
        $submission,
        'ownership_title'
    );

    $test->postJson($url, [
        'check_type' => 'ownership',
        'outcome' => 'verified',
        'method' => 'land_title_review',
        'evidence_reference' => $title->reference,
        'notes' => 'Staff reviewed the ownership title evidence.',
    ])->assertCreated();

    if ($submission->submitter_role === 'authorized_agent') {
        $authorization = approvalEvidence198(
            $submission,
            'owner_authorization'
        );

        $test->postJson($url, [
            'check_type' => 'authorization',
            'outcome' => 'verified',
            'method' => 'owner_authorization_review',
            'evidence_reference' => $authorization->reference,
            'notes' =>
                'Staff reviewed the owner authorization letter.',
        ])->assertCreated();

        if ($withAgentConfirmation) {
            $test->postJson(
                "/api/property-submissions/{$submission->id}/owner-confirmations",
                [
                    'outcome' => 'confirmed',
                    'contact_method' => 'phone_call',
                    'contact_source' =>
                        'independent_official_record',
                    'contact_phone' => '0788000193',
                    'confirmed_owner_name' =>
                        'Example Registered Owner',
                    'confirmed_purpose' =>
                        $submission->submission_purpose,
                    'owner_identity_confirmed' => true,
                    'service_consent_confirmed' => true,
                    'notes' =>
                        'Staff independently confirmed the owner and consent.',
                ]
            )->assertCreated();
        }
    }
}

function approvalEvents198(PropertySubmission $submission)
{
    return DB::table('property_submission_review_events')
        ->where('property_submission_id', $submission->id)
        ->orderBy('id')
        ->get();
}

test('guest and ordinary users cannot approve submissions', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )->assertUnauthorized();

    foreach (['customer', 'agent'] as $role) {
        Sanctum::actingAs(approvalUser198($role));

        $this->postJson(
            approvalUrl198($submission),
            approvalPayload198()
        )->assertForbidden();
    }

    expect($submission->fresh()->status)
        ->toBe('under_review');
});

test('administrator without verification permission is denied', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    Role::findByName('admin')->syncPermissions([]);

    app(PermissionRegistrar::class)
        ->forgetCachedPermissions();

    $admin = approvalUser198('admin');

    expect($admin->can('properties.verify'))->toBeFalse();

    Sanctum::actingAs($admin);

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )->assertForbidden();
});

test('verified owner submission can be approved without publication', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    $admin = approvalUser198();

    Sanctum::actingAs($admin);

    prepareApproval198($this, $submission);

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('is_published', false)
        ->assertJsonPath('converted_property_id', null);

    $fresh = $submission->fresh();

    expect($fresh->status)->toBe('approved');
    expect($fresh->reviewed_by)->toBe($admin->id);
    expect($fresh->reviewed_at)->not->toBeNull();
    expect($fresh->converted_property_id)->toBeNull();

    $events = approvalEvents198($submission);

    expect($events)->toHaveCount(1);
    expect($events[0]->action)->toBe('approved');
    expect($events[0]->from_status)->toBe('under_review');
    expect($events[0]->to_status)->toBe('approved');
    expect($events[0]->reviewer_id)->toBe($admin->id);

    expect(Property::count())->toBe(0);
});

test('authorized agent needs owner confirmation before approval', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id,
        'authorized_agent'
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission, false);

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['owner_confirmation']);

    expect(approvalEvents198($submission))->toHaveCount(0);
});

test('fully verified authorized agent can be approved', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id,
        'authorized_agent'
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission);

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    expect($submission->fresh()->status)->toBe('approved');
    expect(Property::count())->toBe(0);
});

test('approval requires current contact and ownership verification', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    Sanctum::actingAs(approvalUser198());

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['verification']);

    expect(approvalEvents198($submission))->toHaveCount(0);
});

test('withdrawn or altered ownership evidence blocks approval', function (
    string $scenario
) {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission);

    $evidence = PropertySubmissionEvidenceFile::query()
        ->where('property_submission_id', $submission->id)
        ->where('document_type', 'ownership_title')
        ->firstOrFail();

    if ($scenario === 'withdrawn') {
        $evidence->forceFill([
            'withdrawn_at' => now(),
        ])->save();
    }

    if ($scenario === 'tampered') {
        Storage::disk('local')->put(
            $evidence->file_path,
            'Modified file contents.'
        );
    }

    if ($scenario === 'missing') {
        Storage::disk('local')->delete(
            $evidence->file_path
        );
    }

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_reference']);

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect(approvalEvents198($submission))->toHaveCount(0);
})->with([
    'withdrawn',
    'tampered',
    'missing',
]);

test('altered authorization evidence blocks agent approval', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id,
        'authorized_agent'
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission);

    $evidence = PropertySubmissionEvidenceFile::query()
        ->where('property_submission_id', $submission->id)
        ->where('document_type', 'owner_authorization')
        ->firstOrFail();

    Storage::disk('local')->put(
        $evidence->file_path,
        'Altered authorization document.'
    );

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['evidence_reference']);

    expect(approvalEvents198($submission))->toHaveCount(0);
});

test('revoked owner confirmation blocks agent approval', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id,
        'authorized_agent'
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission);

    $this->postJson(
        "/api/property-submissions/{$submission->id}/owner-confirmations",
        [
            'outcome' => 'revoked',
            'contact_method' => 'administrative_review',
            'contact_source' => 'prior_confirmation_record',
            'notes' =>
                'The property owner withdrew authorization to list.',
        ]
    )->assertCreated();

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['owner_confirmation']);

    expect(approvalEvents198($submission))->toHaveCount(0);
});

test('duplicate approval is rejected without duplicate audit', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission);

    $url = approvalUrl198($submission);

    $this->postJson($url, approvalPayload198())
        ->assertOk();

    $this->postJson($url, approvalPayload198())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(approvalEvents198($submission))->toHaveCount(1);
});

test('pending and rejected submissions cannot be approved', function (
    string $status
) {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    Sanctum::actingAs(approvalUser198());

    $submission->forceFill([
        'status' => $status,
    ])->save();

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(approvalEvents198($submission))->toHaveCount(0);
})->with([
    'pending_review',
    'rejected',
]);

test('missing applicant consent blocks approval', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission);

    $submission->forceFill([
        'authorization_confirmed_at' => null,
    ])->save();

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('inactive category blocks approval', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission);

    $this->approvalCategory198->forceFill([
        'is_active' => false,
    ])->save();

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('management submission can be approved without asking price', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id,
        'owner',
        'management'
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission);

    $this->postJson(
        approvalUrl198($submission),
        approvalPayload198()
    )
        ->assertOk()
        ->assertJsonPath('status', 'approved');

    expect(Property::count())->toBe(0);
});

test('request cannot inject approval or publication attributes', function () {
    $submission = approvalSubmission198(
        $this->approvalCategory198->id
    );

    Sanctum::actingAs(approvalUser198());

    prepareApproval198($this, $submission);

    $this->postJson(
        approvalUrl198($submission),
        [
            ...approvalPayload198(),
            'reviewed_by' => 999,
            'status' => 'approved',
            'converted_property_id' => 999,
            'is_published' => true,
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'reviewed_by',
            'status',
            'converted_property_id',
            'is_published',
        ]);

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect(Property::count())->toBe(0);
});
