<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertySubmission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'STOP: Unsafe test database configuration.'
        );
    }

    $this->seed(RolePermissionSeeder::class);

    $this->staffSubmissionCategory = PropertyCategory::create([
        'name' => 'Staff Review House',
        'slug' => 'staff-review-house',
        'is_active' => true,
    ]);
});

function staffSubmissionUser175(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function staffSubmissionRecord175(
    int $categoryId,
    string $status = 'pending_review',
    string $title = 'Property Submitted in Kigali'
): PropertySubmission {
    $submission = PropertySubmission::create([
        'submitter_name' => 'Sample Landlord',
        'submitter_phone' => '0788000012',
        'submitter_email' => 'private-landlord@example.com',
        'submitter_role' => 'owner',
        'submission_purpose' => 'sale',
        'property_title' => $title,
        'property_description' =>
            'Residential property submitted for staff verification.',
        'category_id' => $categoryId,
        'asking_price' => 65000000,
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

test('visitor cannot access submission inbox or details', function () {
    $submission = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id
    );

    $this->getJson('/api/property-submissions')
        ->assertUnauthorized();

    $this->getJson(
        "/api/property-submissions/{$submission->id}"
    )->assertUnauthorized();
});

test('customer cannot view landlord submissions', function () {
    $submission = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id
    );

    Sanctum::actingAs(staffSubmissionUser175('customer'));

    $this->getJson('/api/property-submissions')
        ->assertForbidden();

    $this->getJson(
        "/api/property-submissions/{$submission->id}"
    )->assertForbidden();
});

test('ordinary agent cannot access private submissions', function () {
    $submission = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id
    );

    Sanctum::actingAs(staffSubmissionUser175('agent'));

    $this->getJson('/api/property-submissions')
        ->assertForbidden();

    $this->getJson(
        "/api/property-submissions/{$submission->id}"
    )->assertForbidden();
});

test('admin role without verification permission is denied', function () {
    $submission = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id
    );

    $role = Role::findByName('admin');

    $role->syncPermissions([]);

    app(PermissionRegistrar::class)
        ->forgetCachedPermissions();

    $admin = staffSubmissionUser175('admin');

    expect($admin->can('properties.verify'))->toBeFalse();

    Sanctum::actingAs($admin);

    $this->getJson('/api/property-submissions')
        ->assertForbidden();

    $this->getJson(
        "/api/property-submissions/{$submission->id}"
    )->assertForbidden();
});

test('authorized administrator can view submission inbox', function () {
    $submission = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id
    );

    Sanctum::actingAs(staffSubmissionUser175('super_admin'));

    $this->getJson('/api/property-submissions')
        ->assertOk()
        ->assertJsonPath('data.0.reference', $submission->reference)
        ->assertJsonPath('data.0.status', 'pending_review')
        ->assertJsonPath('data.0.submitter_name', 'Sample Landlord')
        ->assertJsonMissingPath('data.0.submitter_email')
        ->assertJsonMissingPath('data.0.review_notes');

    expect(PropertySubmission::count())->toBe(1);
});

test('authorized administrator can view submission details', function () {
    $submission = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id
    );

    Sanctum::actingAs(staffSubmissionUser175('super_admin'));

    $this->getJson(
        "/api/property-submissions/{$submission->id}"
    )
        ->assertOk()
        ->assertJsonPath(
            'submission.reference',
            $submission->reference
        )
        ->assertJsonPath(
            'submission.submitter_email',
            'private-landlord@example.com'
        )
        ->assertJsonPath(
            'submission.category.name',
            'Staff Review House'
        )
        ->assertJsonPath(
            'submission.status',
            'pending_review'
        )
        ->assertJsonPath(
            'submission.converted_property',
            null
        );

    expect(Property::count())->toBe(0);
});

test('staff can filter pending and under review submissions', function () {
    $pending = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id,
        'pending_review',
        'Pending House'
    );

    $underReview = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id,
        'under_review',
        'Under Review House'
    );

    Sanctum::actingAs(staffSubmissionUser175('super_admin'));

    $this->getJson(
        '/api/property-submissions?status=pending_review'
    )
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $pending->reference);

    $this->getJson(
        '/api/property-submissions?status=under_review'
    )
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $underReview->reference);
});

test('staff can search by reference and property title', function () {
    $first = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id,
        'pending_review',
        'Unique Kacyiru Apartment'
    );

    staffSubmissionRecord175(
        $this->staffSubmissionCategory->id,
        'pending_review',
        'Different Residential House'
    );

    Sanctum::actingAs(staffSubmissionUser175('super_admin'));

    $this->getJson(
        '/api/property-submissions?search=' . $first->reference
    )
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $first->reference);

    $this->getJson(
        '/api/property-submissions?search=Unique%20Kacyiru'
    )
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $first->reference);
});

test('staff inbox validates pagination and filters', function () {
    staffSubmissionRecord175(
        $this->staffSubmissionCategory->id
    );

    Sanctum::actingAs(staffSubmissionUser175('super_admin'));

    $this->getJson(
        '/api/property-submissions?per_page=101'
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);

    $this->getJson(
        '/api/property-submissions?status=published'
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $this->getJson(
        '/api/property-submissions?per_page=1'
    )
        ->assertOk()
        ->assertJsonPath('per_page', 1);
});

test('opening a submission never modifies or publishes it', function () {
    $submission = staffSubmissionRecord175(
        $this->staffSubmissionCategory->id
    );

    $originalStatus = $submission->status;
    $originalReference = $submission->reference;
    $originalUpdatedAt = $submission->updated_at?->toISOString();

    Sanctum::actingAs(staffSubmissionUser175('super_admin'));

    $this->getJson('/api/property-submissions')
        ->assertOk();

    $this->getJson(
        "/api/property-submissions/{$submission->id}"
    )->assertOk();

    $submission->refresh();

    expect($submission->status)->toBe($originalStatus);
    expect($submission->reference)->toBe($originalReference);
    expect($submission->updated_at?->toISOString())
        ->toBe($originalUpdatedAt);

    expect($submission->reviewed_by)->toBeNull();
    expect($submission->converted_property_id)->toBeNull();

    expect(PropertySubmission::count())->toBe(1);
    expect(Property::count())->toBe(0);
});

test('unknown submission cannot be viewed', function () {
    Sanctum::actingAs(staffSubmissionUser175('super_admin'));

    $this->getJson('/api/property-submissions/999999')
        ->assertNotFound();
});
