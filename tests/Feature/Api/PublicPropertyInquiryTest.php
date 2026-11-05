<?php

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->inquiryUser = User::factory()->create([
        'status' => 'active',
    ]);

    $this->inquiryCategory = PropertyCategory::create([
        'name' => 'Residential House',
        'slug' => 'residential-house',
        'is_active' => true,
    ]);
});

function inquiryProperty(
    User $creator,
    PropertyCategory $category,
    string $type = 'sale'
): Property {
    $key = uniqid();

    $property = Property::create([
        'reference' => 'GI-' . $key,
        'title' => 'House in Kigali',
        'slug' => 'inquiry-house-' . $key,
        'category_id' => $category->id,
        'listing_type' => $type,
        'asking_price' => 50000000,
        'created_by' => $creator->id,
    ]);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    return $property;
}

test('visitor can inquire about published sale property', function () {
    $property = inquiryProperty(
        $this->inquiryUser,
        $this->inquiryCategory
    );

    $this->postJson(
        "/api/public/properties/{$property->slug}/inquiries",
        [
            'name' => 'Alice Buyer',
            'phone' => '0788000001',
            'message' => 'I would like to visit this house.',
        ]
    )
        ->assertCreated()
        ->assertJsonStructure([
            'message',
            'inquiry_reference',
        ])
        ->assertJsonMissingPath('customer');

    $customer = Customer::firstOrFail();
    $lead = Lead::firstOrFail();

    expect($customer->name)->toBe('Alice Buyer');
    expect($customer->customer_type)->toBe('buyer');
    expect($customer->created_by)->toBeNull();

    expect($lead->property_id)->toBe($property->id);
    expect($lead->customer_id)->toBe($customer->id);
    expect($lead->source)->toBe('website');
    expect($lead->status)->toBe('new');
    expect($lead->lead_type)->toBe('buy');
    expect($lead->assigned_agent_id)->toBeNull();
});

test('rental property inquiry creates a rental lead', function () {
    $property = inquiryProperty(
        $this->inquiryUser,
        $this->inquiryCategory,
        'rent'
    );

    $this->postJson(
        "/api/public/properties/{$property->slug}/inquiries",
        [
            'name' => 'Rental Customer',
            'email' => 'renter@example.com',
            'message' => 'Is this property available for rent?',
        ]
    )->assertCreated();

    expect(Customer::first()->customer_type)->toBe('tenant');
    expect(Lead::first()->lead_type)->toBe('rent');
});

test('visitors cannot inquire about unpublished property', function () {
    $property = inquiryProperty(
        $this->inquiryUser,
        $this->inquiryCategory
    );

    $property->forceFill([
        'is_published' => false,
    ])->save();

    $this->postJson(
        "/api/public/properties/{$property->slug}/inquiries",
        [
            'name' => 'Test Visitor',
            'phone' => '0788000002',
            'message' => 'Interested in this property.',
        ]
    )->assertNotFound();

    expect(Customer::count())->toBe(0);
    expect(Lead::count())->toBe(0);
});

test('public inquiry requires contact information and message', function () {
    $property = inquiryProperty(
        $this->inquiryUser,
        $this->inquiryCategory
    );

    $this->postJson(
        "/api/public/properties/{$property->slug}/inquiries",
        ['name' => 'Incomplete Inquiry']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'phone',
            'email',
            'message',
        ]);

    expect(Lead::count())->toBe(0);
});

test('visitor cannot control internal lead information', function () {
    $property = inquiryProperty(
        $this->inquiryUser,
        $this->inquiryCategory
    );

    $this->postJson(
        "/api/public/properties/{$property->slug}/inquiries",
        [
            'name' => 'Test Visitor',
            'phone' => '0788000003',
            'message' => 'Interested in buying.',
            'status' => 'won',
            'priority' => 'high',
            'source' => 'staff',
            'assigned_agent_id' => $this->inquiryUser->id,
            'created_by' => $this->inquiryUser->id,
        ]
    )->assertCreated();

    $lead = Lead::firstOrFail();

    expect($lead->status)->toBe('new');
    expect($lead->priority)->toBe('medium');
    expect($lead->source)->toBe('website');
    expect($lead->assigned_agent_id)->toBeNull();
    expect($lead->created_by)->toBeNull();
});

test('public inquiry response never exposes private crm fields', function () {
    $property = inquiryProperty(
        $this->inquiryUser,
        $this->inquiryCategory
    );

    $this->postJson(
        "/api/public/properties/{$property->slug}/inquiries",
        [
            'name' => 'Private Visitor',
            'email' => 'private@example.com',
            'message' => 'Please call me.',
        ]
    )
        ->assertCreated()
        ->assertJsonMissingPath('customer_id')
        ->assertJsonMissingPath('assigned_agent_id')
        ->assertJsonMissingPath('internal_notes')
        ->assertDontSee('private@example.com');
});

test('public property inquiries are rate limited', function () {
    $property = inquiryProperty(
        $this->inquiryUser,
        $this->inquiryCategory
    );

    $url = "/api/public/properties/{$property->slug}/inquiries";

    $payload = [
        'name' => 'Rate Limit Visitor',
        'phone' => '0788000001',
        'message' => 'I am interested in this property.',
    ];

    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.150',
        ])->postJson($url, $payload)->assertCreated();
    }

    $this->withServerVariables([
        'REMOTE_ADDR' => '192.0.2.150',
    ])->postJson($url, $payload)->assertStatus(429);

    expect(Lead::count())->toBe(5);
    expect(Customer::count())->toBe(5);
});
