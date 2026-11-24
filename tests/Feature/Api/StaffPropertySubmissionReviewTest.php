<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertySubmission;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'STOP: Unsafe database configuration.'
        );
    }

    $this->seed(RolePermissionSeeder::class);

    $this->reviewCategory179 = PropertyCategory::create([
        'name' => 'Review Test House',
        'slug' => 'review-test-house',
        'is_active' => true,
    ]);
});

function reviewUser179(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function reviewApplication179(
    int $categoryId,
    string $status = 'pending_review'
): PropertySubmission {
    $submission = PropertySubmission::create([
        'submitter_name' => 'Review Test Landlord',
        'submitter_phone' => '0788000099',
        'submitter_email' => 'review-owner@example.com',
        'submitter_role' => 'owner',
        'submission_purpose' => 'sale',
        'property_title' => 'House Awaiting Verification',
        'property_description' =>
            'A house in Kigali awaiting staff verification.',
        'category_id' => $categoryId,
        'asking_price' => 60000000,
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

function reviewEvents179(int $submissionId)
{
    return DB::table('property_submission_review_events')
        ->where('property_submission_id', $submissionId)
        ->orderBy('id')
        ->get();
}

test('visitor cannot start or reject a review', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id
    );

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/start-review"
    )->assertUnauthorized();

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/reject",
        ['reason' => 'Ownership could not be verified.']
    )->assertUnauthorized();

    expect($submission->fresh()->status)
        ->toBe('pending_review');

    expect(reviewEvents179($submission->id))->toHaveCount(0);
});

test('customer cannot change submission review status', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id
    );

    Sanctum::actingAs(reviewUser179('customer'));

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/start-review"
    )->assertForbidden();

    expect($submission->fresh()->status)
        ->toBe('pending_review');

    expect(reviewEvents179($submission->id))->toHaveCount(0);
});

test('ordinary agent cannot reject submissions', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id,
        'under_review'
    );

    Sanctum::actingAs(reviewUser179('agent'));

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/reject",
        ['reason' => 'The submitted documents were insufficient.']
    )->assertForbidden();

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect(reviewEvents179($submission->id))->toHaveCount(0);
});

test('admin without verification permission is denied', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id
    );

    Role::findByName('admin')->syncPermissions([]);

    app(PermissionRegistrar::class)
        ->forgetCachedPermissions();

    $admin = reviewUser179('admin');

    expect($admin->can('properties.verify'))->toBeFalse();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/start-review"
    )->assertForbidden();

    expect(reviewEvents179($submission->id))->toHaveCount(0);
});

test('administrator can start review and create audit history', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id
    );

    $admin = reviewUser179('super_admin');

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/start-review",
        ['reason' => 'Beginning ownership and details verification.']
    )
        ->assertOk()
        ->assertJsonPath('status', 'under_review')
        ->assertJsonPath(
            'submission_reference',
            $submission->reference
        )
        ->assertJsonStructure(['review_event_id']);

    $submission->refresh();

    expect($submission->status)->toBe('under_review');
    expect($submission->reviewed_by)->toBeNull();
    expect($submission->converted_property_id)->toBeNull();

    $events = reviewEvents179($submission->id);

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->action)->toBe('review_started');
    expect($event->from_status)->toBe('pending_review');
    expect($event->to_status)->toBe('under_review');
    expect($event->reviewer_id)->toBe($admin->id);
    expect($event->reviewer_name)->toBe($admin->name);
    expect($event->created_at)->not->toBeNull();

    expect(Property::count())->toBe(0);
});

test('starting review twice is blocked without duplicate audit', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id
    );

    Sanctum::actingAs(reviewUser179('super_admin'));

    $url =
        "/api/property-submissions/{$submission->id}/start-review";

    $this->patchJson($url)->assertOk();

    $this->patchJson($url)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect(reviewEvents179($submission->id))->toHaveCount(1);
});

test('pending submission cannot be rejected directly', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id
    );

    Sanctum::actingAs(reviewUser179('super_admin'));

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/reject",
        ['reason' => 'Submitted ownership information is invalid.']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($submission->fresh()->status)
        ->toBe('pending_review');

    expect(reviewEvents179($submission->id))->toHaveCount(0);
});

test('rejection requires a meaningful written reason', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id,
        'under_review'
    );

    Sanctum::actingAs(reviewUser179('super_admin'));

    $url =
        "/api/property-submissions/{$submission->id}/reject";

    $this->patchJson($url, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $this->patchJson($url, ['reason' => 'No'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect(reviewEvents179($submission->id))->toHaveCount(0);
});

test('administrator can reject and record full audit details', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id
    );

    $admin = reviewUser179('super_admin');

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/start-review"
    )->assertOk();

    $reason = 'The applicant did not provide valid ownership evidence.';

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/reject",
        ['reason' => $reason]
    )
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath(
            'submission_reference',
            $submission->reference
        );

    $submission->refresh();

    expect($submission->status)->toBe('rejected');
    expect($submission->reviewed_by)->toBe($admin->id);
    expect($submission->review_notes)->toBe($reason);
    expect($submission->reviewed_at)->not->toBeNull();
    expect($submission->converted_property_id)->toBeNull();

    $events = reviewEvents179($submission->id);

    expect($events)->toHaveCount(2);

    expect($events[0]->action)->toBe('review_started');
    expect($events[1]->action)->toBe('rejected');
    expect($events[1]->from_status)->toBe('under_review');
    expect($events[1]->to_status)->toBe('rejected');
    expect($events[1]->reviewer_id)->toBe($admin->id);
    expect($events[1]->reviewer_name)->toBe($admin->name);
    expect($events[1]->reason)->toBe($reason);

    expect(Property::count())->toBe(0);
});

test('rejected submission cannot be rejected again', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id,
        'rejected'
    );

    Sanctum::actingAs(reviewUser179('super_admin'));

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/reject",
        ['reason' => 'This application has already been rejected.']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(reviewEvents179($submission->id))->toHaveCount(0);
});

test('converted submission cannot be rejected', function () {
    $admin = reviewUser179('super_admin');

    $submission = reviewApplication179(
        $this->reviewCategory179->id,
        'under_review'
    );

    $property = Property::create([
        'reference' => 'GI-TEST-179',
        'title' => 'Existing Internal Property',
        'slug' => 'existing-internal-property-179',
        'category_id' => $this->reviewCategory179->id,
        'listing_type' => 'sale',
        'asking_price' => 60000000,
        'created_by' => $admin->id,
    ]);

    $submission->forceFill([
        'converted_property_id' => $property->id,
    ])->save();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/reject",
        ['reason' => 'Trying to reject a converted application.']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($submission->fresh()->status)
        ->toBe('under_review');

    expect(reviewEvents179($submission->id))->toHaveCount(0);
});

test('visitor cannot inject internal review fields', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id
    );

    Sanctum::actingAs(reviewUser179('super_admin'));

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/start-review",
        [
            'status' => 'approved',
            'reviewed_by' => 999,
            'converted_property_id' => 1,
            'is_published' => true,
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'status',
            'reviewed_by',
            'converted_property_id',
            'is_published',
        ]);

    expect($submission->fresh()->status)
        ->toBe('pending_review');

    expect(reviewEvents179($submission->id))->toHaveCount(0);
    expect(Property::count())->toBe(0);
});

test('rejected submission cannot reenter review', function () {
    $submission = reviewApplication179(
        $this->reviewCategory179->id,
        'rejected'
    );

    Sanctum::actingAs(reviewUser179('super_admin'));

    $this->patchJson(
        "/api/property-submissions/{$submission->id}/start-review"
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(reviewEvents179($submission->id))->toHaveCount(0);
});
