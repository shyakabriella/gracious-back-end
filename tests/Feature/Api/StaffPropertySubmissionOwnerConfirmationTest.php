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
            'STOP: Owner confirmation tests require in-memory SQLite.'
        );
    }

    Storage::fake('local');

    config()->set(
        'app.key',
        'base64:' . base64_encode(str_repeat('Q', 32))
    );

    $this->seed(RolePermissionSeeder::class);

    $this->confirmationCategory195 = PropertyCategory::create([
        'name' => 'Owner Confirmation Test',
        'slug' => 'owner-confirmation-test-195a',
        'is_active' => true,
    ]);

});

function ownerUser195a(string $role = 'super_admin'): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function ownerSubmission195a(
    int $categoryId,
    string $role = 'authorized_agent'
): PropertySubmission {
    $submission = PropertySubmission::create([
        'submitter_name' => 'Example Property Agent',
        'submitter_phone' => '0788000192',
        'submitter_email' => 'agent195a@example.com',
        'submitter_role' => $role,
        'owner_name' => $role === 'authorized_agent'
            ? 'Example Registered Owner'
            : null,
        'owner_phone' => '0788000193',
        'submission_purpose' => 'sale',
        'property_title' => 'Kigali Owner Confirmation Property',
        'property_description' =>
            'Private property awaiting independent owner confirmation.',
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

function ownerUrl195a(PropertySubmission $submission): string
{
    return "/api/property-submissions/{$submission->id}/owner-confirmations";
}

function ownerPayload195a(string $outcome = 'confirmed'): array
{
    if ($outcome === 'revoked') {
        return [
            'outcome' => 'revoked',
            'contact_method' => 'administrative_review',
            'contact_source' => 'prior_confirmation_record',
            'notes' =>
                'The previous owner confirmation was formally revoked.',
        ];
    }

    $payload = [
        'outcome' => $outcome,
        'contact_method' => 'phone_call',
        'contact_source' => 'independent_official_record',
        'contact_phone' => '0788000193',
        'notes' =>
            'Administrator independently contacted the property owner.',
    ];

    if ($outcome === 'confirmed') {
        $payload += [
            'confirmed_owner_name' => 'Example Registered Owner',
            'confirmed_purpose' => 'sale',
            'owner_identity_confirmed' => true,
            'service_consent_confirmed' => true,
        ];
    }

    return $payload;
}

function ownerEvidence195a(
    PropertySubmission $submission,
    string $type
): PropertySubmissionEvidenceFile {
    $label = $type === 'ownership_title'
        ? 'TITLE'
        : 'AUTH';

    $reference = "EV-{$label}-195A-{$submission->id}";

    $contents = "%PDF-1.4\n% Private {$type} test\n";

    $path = "property-submissions/{$submission->id}/evidence/{$type}-195a.pdf";

    Storage::disk('local')->put($path, $contents);

    $record = new PropertySubmissionEvidenceFile();

    $record->forceFill([
        'reference' => $reference,
        'property_submission_id' => $submission->id,
        'document_type' => $type,
        'title' => 'Private Evidence Test',
        'original_name' => "{$type}.pdf",
        'storage_disk' => 'local',
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($contents),
        'sha256' => hash('sha256', $contents),
    ])->save();

    return $record;
}

function ownerPrepare195a(
    $test,
    PropertySubmission $submission
): void {
    $url = "/api/property-submissions/{$submission->id}/verifications";

    $test->postJson($url, [
        'check_type' => 'contact',
        'outcome' => 'verified',
        'method' => 'phone_call',
        'notes' => 'Administrator contacted the property agent.',
    ])->assertCreated();

    $title = ownerEvidence195a(
        $submission,
        'ownership_title'
    );

    $test->postJson($url, [
        'check_type' => 'ownership',
        'outcome' => 'verified',
        'method' => 'land_title_review',
        'evidence_reference' => $title->reference,
        'notes' => 'Administrator reviewed the land title evidence.',
    ])->assertCreated();

    $authorization = ownerEvidence195a(
        $submission,
        'owner_authorization'
    );

    $test->postJson($url, [
        'check_type' => 'authorization',
        'outcome' => 'verified',
        'method' => 'owner_authorization_review',
        'evidence_reference' => $authorization->reference,
        'notes' => 'Administrator reviewed the authorization letter.',
    ])->assertCreated();
}

function ownerEvents195a(PropertySubmission $submission)
{
    return DB::table(
        'property_submission_owner_confirmation_events'
    )
        ->where('property_submission_id', $submission->id)
        ->orderBy('id')
        ->get();
}

test('guest and ordinary users cannot access owner confirmations', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    $url = ownerUrl195a($submission);

    $this->postJson($url, ownerPayload195a())
        ->assertUnauthorized();

    $this->getJson($url)->assertUnauthorized();

    foreach (['customer', 'agent'] as $role) {
        Sanctum::actingAs(ownerUser195a($role));

        $this->postJson($url, ownerPayload195a())
            ->assertForbidden();

        $this->getJson($url)->assertForbidden();
    }

    expect(ownerEvents195a($submission))->toHaveCount(0);
});

test('admin without verification permission cannot confirm', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Role::findByName('admin')->syncPermissions([]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = ownerUser195a('admin');

    expect($admin->can('properties.verify'))->toBeFalse();

    Sanctum::actingAs($admin);

    $this->postJson(
        ownerUrl195a($submission),
        ownerPayload195a()
    )->assertForbidden();

    expect(ownerEvents195a($submission))->toHaveCount(0);
});

test('valid confirmation records private contact evidence', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    $admin = ownerUser195a();

    Sanctum::actingAs($admin);

    ownerPrepare195a($this, $submission);

    $url = ownerUrl195a($submission);

    $this->postJson($url, ownerPayload195a())
        ->assertCreated()
        ->assertJsonPath('outcome', 'confirmed')
        ->assertJsonPath('contact_phone_last4', '0193')
        ->assertJsonMissingPath('contact_fingerprint')
        ->assertJsonMissingPath('contact_phone');

    $event = ownerEvents195a($submission)->first();

    expect($event->reviewer_id)->toBe($admin->id);
    expect($event->reviewer_name)->toBe($admin->name);
    expect($event->confirmed_owner_name)
        ->toBe('Example Registered Owner');
    expect($event->confirmed_purpose)->toBe('sale');

    expect((bool) $event->owner_identity_confirmed)
        ->toBeTrue();

    expect((bool) $event->service_consent_confirmed)
        ->toBeTrue();

    expect($event->contact_phone_last4)->toBe('0193');

    expect($event->contact_fingerprint)->toBe(
        hash_hmac(
            'sha256',
            'gracious-owner-confirmation:v1:250788000193',
            str_repeat('Q', 32)
        )
    );

    $this->getJson($url . '?per_page=1')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.outcome', 'confirmed')
        ->assertJsonMissingPath('data.0.contact_fingerprint')
        ->assertJsonMissingPath('data.0.contact_phone');

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect(Property::count())->toBe(0);
});

test('confirmation can be recorded in person without phone', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $submission);

    $payload = ownerPayload195a();

    $payload['contact_method'] = 'in_person';
    $payload['contact_source'] = 'in_person_identity_document';

    unset($payload['contact_phone']);

    $this->postJson(ownerUrl195a($submission), $payload)
        ->assertCreated()
        ->assertJsonPath('contact_phone_last4', null);

    $event = ownerEvents195a($submission)->first();

    expect($event->contact_fingerprint)->toBeNull();
});

test('staff can record denial without positive acknowledgements', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    $this->postJson(
        ownerUrl195a($submission),
        ownerPayload195a('denied')
    )
        ->assertCreated()
        ->assertJsonPath('outcome', 'denied');

    $event = ownerEvents195a($submission)->first();

    expect($event->outcome)->toBe('denied');

    expect((bool) $event->owner_identity_confirmed)
        ->toBeFalse();

    expect((bool) $event->service_consent_confirmed)
        ->toBeFalse();

    expect(Property::count())->toBe(0);
});

test('staff can revoke confirmation without positive acknowledgements', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $submission);

    $url = ownerUrl195a($submission);

    $this->postJson($url, ownerPayload195a())
        ->assertCreated();

    $this->postJson($url, ownerPayload195a('revoked'))
        ->assertCreated()
        ->assertJsonPath('outcome', 'revoked');

    $events = ownerEvents195a($submission);

    expect($events)->toHaveCount(2);
    expect($events[0]->outcome)->toBe('confirmed');
    expect($events[1]->outcome)->toBe('revoked');

    $this->postJson($url, ownerPayload195a('revoked'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(ownerEvents195a($submission))->toHaveCount(2);
});

test('revocation before confirmation is rejected', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    $this->postJson(
        ownerUrl195a($submission),
        ownerPayload195a('revoked')
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(ownerEvents195a($submission))->toHaveCount(0);
});

test('duplicate confirmation and silent denial are blocked', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $submission);

    $url = ownerUrl195a($submission);

    $this->postJson($url, ownerPayload195a())
        ->assertCreated();

    $this->postJson($url, ownerPayload195a())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    $this->postJson($url, ownerPayload195a('denied'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(ownerEvents195a($submission))->toHaveCount(1);
});

test('positive confirmation requires prior verifications', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    $this->postJson(
        ownerUrl195a($submission),
        ownerPayload195a()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(ownerEvents195a($submission))->toHaveCount(0);
});

test('revoked prior verification blocks confirmation', function (
    string $type
) {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $submission);

    $method = match ($type) {
        'contact' => 'phone_call',
        'ownership' => 'land_title_review',
        'authorization' => 'owner_authorization_review',
    };

    $this->postJson(
        "/api/property-submissions/{$submission->id}/verifications",
        [
            'check_type' => $type,
            'outcome' => 'revoked',
            'method' => $method,
            'notes' =>
                'Administrator formally revoked this verification.',
        ]
    )->assertCreated();

    $this->postJson(
        ownerUrl195a($submission),
        ownerPayload195a()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['outcome']);

    expect(ownerEvents195a($submission))->toHaveCount(0);
})->with([
    'contact',
    'ownership',
    'authorization',
]);

test('non-agent or unnamed-owner submissions are rejected', function (
    string $scenario
) {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id,
        $scenario === 'owner_role'
            ? 'owner'
            : 'authorized_agent'
    );

    if ($scenario === 'missing_owner') {
        $submission->forceFill([
            'owner_name' => null,
        ])->save();
    }

    Sanctum::actingAs(ownerUser195a());

    $this->postJson(
        ownerUrl195a($submission),
        ownerPayload195a('denied')
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(ownerEvents195a($submission))->toHaveCount(0);
})->with([
    'owner_role',
    'missing_owner',
]);

test('confirmed owner and service must match submission', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $submission);

    $url = ownerUrl195a($submission);

    $nameMismatch = ownerPayload195a();
    $nameMismatch['confirmed_owner_name'] = 'Different Owner';

    $this->postJson($url, $nameMismatch)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmed_owner_name']);

    $purposeMismatch = ownerPayload195a();
    $purposeMismatch['confirmed_purpose'] = 'rent';

    $this->postJson($url, $purposeMismatch)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmed_purpose']);

    expect(ownerEvents195a($submission))->toHaveCount(0);
});

test('positive confirmation requires accepted identity and consent', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $submission);

    $payload = ownerPayload195a();

    unset(
        $payload['owner_identity_confirmed'],
        $payload['service_consent_confirmed']
    );

    $this->postJson(ownerUrl195a($submission), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'owner_identity_confirmed',
            'service_consent_confirmed',
        ]);

    $payload = ownerPayload195a();

    $payload['owner_identity_confirmed'] = false;
    $payload['service_consent_confirmed'] = false;

    $this->postJson(ownerUrl195a($submission), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'owner_identity_confirmed',
            'service_consent_confirmed',
        ]);

    expect(ownerEvents195a($submission))->toHaveCount(0);
});

test('non-confirmed decisions cannot inject positive consent', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    $payload = ownerPayload195a('denied');

    $payload['owner_identity_confirmed'] = true;
    $payload['service_consent_confirmed'] = true;

    $this->postJson(ownerUrl195a($submission), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'owner_identity_confirmed',
            'service_consent_confirmed',
        ]);

    expect(ownerEvents195a($submission))->toHaveCount(0);
});

test('invalid contact source and telephone are rejected', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $submission);

    $url = ownerUrl195a($submission);

    $source = ownerPayload195a();
    $source['contact_source'] = 'in_person_identity_document';

    $this->postJson($url, $source)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_source']);

    $phone = ownerPayload195a();
    $phone['contact_phone'] = '0710000000';

    $this->postJson($url, $phone)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_phone']);

    $missingPhone = ownerPayload195a();

    unset($missingPhone['contact_phone']);

    $this->postJson($url, $missingPhone)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_phone']);

    expect(ownerEvents195a($submission))->toHaveCount(0);
});

test('internal workflow and audit fields cannot be injected', function () {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $submission);

    $payload = [
        ...ownerPayload195a(),
        'reviewer_id' => 999,
        'contact_fingerprint' => str_repeat('a', 64),
        'status' => 'approved',
        'is_published' => true,
    ];

    $this->postJson(
        ownerUrl195a($submission),
        $payload
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'reviewer_id',
            'contact_fingerprint',
            'status',
            'is_published',
        ]);

    expect(ownerEvents195a($submission))->toHaveCount(0);
    expect(Property::count())->toBe(0);
});

test('pending and rejected submissions cannot be confirmed', function (
    string $status
) {
    $submission = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $submission);

    $submission->forceFill([
        'status' => $status,
    ])->save();

    $this->postJson(
        ownerUrl195a($submission),
        ownerPayload195a()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(ownerEvents195a($submission))->toHaveCount(0);
})->with([
    'pending_review',
    'rejected',
]);

test('confirmation history remains separate for each submission', function () {
    $first = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    $second = ownerSubmission195a(
        $this->confirmationCategory195->id
    );

    Sanctum::actingAs(ownerUser195a());

    ownerPrepare195a($this, $first);

    $this->postJson(
        ownerUrl195a($first),
        ownerPayload195a()
    )->assertCreated();

    $this->getJson(ownerUrl195a($second))
        ->assertOk()
        ->assertJsonPath('total', 0);

    expect(ownerEvents195a($second))->toHaveCount(0);
});
