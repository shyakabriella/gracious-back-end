<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyOwner;
use App\Models\PropertySubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (
        config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== ':memory:'
    ) {
        throw new RuntimeException(
            'Unsafe test database configuration.'
        );
    }

    $this->submissionCategory = PropertyCategory::create([
        'name' => 'Submission Test House',
        'slug' => 'submission-test-house',
        'is_active' => true,
    ]);
});

function graciousSubmissionPayload171(int $categoryId): array
{
    return [
        'submitter_name' => 'Sample Property Owner',
        'submitter_phone' => '0788000001',
        'submitter_email' => 'landlord@example.com',
        'submitter_role' => 'owner',

        'submission_purpose' => 'sale',
        'property_title' => 'Modern House in Kigali',

        'property_description' =>
            'A spacious residential house with parking '
            . 'and a beautiful garden in Kigali.',

        'category_id' => $categoryId,

        'asking_price' => 55000000,
        'currency' => 'RWF',
        'is_negotiable' => true,

        'province' => 'Kigali City',
        'district' => 'Gasabo',
        'sector' => 'Kimironko',

        'bedrooms' => 4,
        'bathrooms' => 3,

        'terms_accepted' => true,
        'authorization_confirmed' => true,
    ];
}

test('anonymous landlord can submit valid property', function () {
    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    $this->postJson(
        '/api/public/property-submissions',
        $payload
    )
        ->assertCreated()
        ->assertJsonPath('status', 'pending_review')
        ->assertJsonStructure([
            'message',
            'submission_reference',
            'status',
        ])
        ->assertJsonMissingPath('reviewed_by')
        ->assertJsonMissingPath('contact_verified_at')
        ->assertJsonMissingPath('converted_property_id')
        ->assertDontSee('landlord@example.com');

    $submission = PropertySubmission::firstOrFail();

    expect($submission->reference)
        ->toStartWith('SUB-');

    expect($submission->status)
        ->toBe('pending_review');

    expect($submission->source)
        ->toBe('website');

    expect($submission->contact_verified_at)
        ->toBeNull();

    expect($submission->reviewed_by)
        ->toBeNull();

    expect($submission->converted_property_id)
        ->toBeNull();

    expect($submission->terms_accepted_at)
        ->not->toBeNull();

    expect($submission->authorization_confirmed_at)
        ->not->toBeNull();

    expect(Property::count())->toBe(0);
    expect(PropertyOwner::count())->toBe(0);
});

test('submissions receive unique references', function () {
    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    $this->postJson(
        '/api/public/property-submissions',
        $payload
    )->assertCreated();

    $this->postJson(
        '/api/public/property-submissions',
        [
            ...$payload,
            'property_title' => 'Another House in Kigali',
        ]
    )->assertCreated();

    $references = PropertySubmission::pluck('reference');

    expect($references)->toHaveCount(2);

    expect($references->unique()->count())->toBe(2);

    expect(Property::count())->toBe(0);
});

test('required application fields are validated', function () {
    $this->postJson(
        '/api/public/property-submissions',
        []
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'submitter_name',
            'submitter_phone',
            'submitter_role',
            'submission_purpose',
            'property_title',
            'property_description',
            'category_id',
            'province',
            'district',
            'sector',
            'terms_accepted',
            'authorization_confirmed',
        ]);

    expect(PropertySubmission::count())->toBe(0);
});

test('invalid price phone and location are rejected', function () {
    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    $this->postJson(
        '/api/public/property-submissions',
        [
            ...$payload,
            'asking_price' => -500,
            'submitter_phone' => 'invalid-phone',
            'latitude' => 100,
            'longitude' => 200,
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'asking_price',
            'submitter_phone',
            'latitude',
            'longitude',
        ]);

    expect(PropertySubmission::count())->toBe(0);
});

test('invalid property category is rejected', function () {
    $payload = graciousSubmissionPayload171(999999);

    $this->postJson(
        '/api/public/property-submissions',
        $payload
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id']);

    expect(PropertySubmission::count())->toBe(0);
});

test('inactive property category is rejected', function () {
    $this->submissionCategory->update([
        'is_active' => false,
    ]);

    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    $this->postJson(
        '/api/public/property-submissions',
        $payload
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id']);

    expect(PropertySubmission::count())->toBe(0);
});

test('authorized agents must identify property owner', function () {
    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    $this->postJson(
        '/api/public/property-submissions',
        [
            ...$payload,
            'submitter_role' => 'authorized_agent',
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['owner_name']);

    expect(PropertySubmission::count())->toBe(0);
});

test('landlord must accept terms and authorization', function () {
    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    $this->postJson(
        '/api/public/property-submissions',
        [
            ...$payload,
            'terms_accepted' => false,
            'authorization_confirmed' => false,
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'terms_accepted',
            'authorization_confirmed',
        ]);

    expect(PropertySubmission::count())->toBe(0);
});

test('visitor cannot supply internal workflow values', function () {
    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    $this->postJson(
        '/api/public/property-submissions',
        [
            ...$payload,
            'reference' => 'FAKE-REFERENCE',
            'status' => 'approved',
            'source' => 'staff',
            'reviewed_by' => 1,
            'review_notes' => 'Approved by visitor',
            'is_published' => true,
            'converted_property_id' => 1,
            'created_by' => 1,
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'reference',
            'status',
            'source',
            'reviewed_by',
            'review_notes',
            'is_published',
            'converted_property_id',
            'created_by',
        ]);

    expect(PropertySubmission::count())->toBe(0);
    expect(Property::count())->toBe(0);
});

test('unapproved media uploads cannot bypass validation', function () {
    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    $this->postJson(
        '/api/public/property-submissions',
        [
            ...$payload,
            'photos' => ['unvalidated-photo.jpg'],
            'documents' => ['private-document.pdf'],
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'photos',
            'documents',
        ]);

    expect(PropertySubmission::count())->toBe(0);
});

test('management inquiry can omit asking price', function () {
    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    unset($payload['asking_price']);

    $this->postJson(
        '/api/public/property-submissions',
        [
            ...$payload,
            'submission_purpose' => 'management',
        ]
    )->assertCreated();

    $submission = PropertySubmission::firstOrFail();

    expect($submission->asking_price)->toBeNull();
    expect($submission->status)->toBe('pending_review');
});

test('public submissions are rate limited', function () {
    $payload = graciousSubmissionPayload171(
        $this->submissionCategory->id
    );

    for ($i = 1; $i <= 5; $i++) {
        $this->postJson(
            '/api/public/property-submissions',
            [
                ...$payload,
                'property_title' => "Test House Number {$i}",
            ]
        )->assertCreated();
    }

    $this->postJson(
        '/api/public/property-submissions',
        [
            ...$payload,
            'property_title' => 'Sixth Test House',
        ]
    )->assertStatus(429);

    expect(PropertySubmission::count())->toBe(5);
    expect(Property::count())->toBe(0);
});
