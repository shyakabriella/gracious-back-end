<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyRental;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function rentalApiUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function rentalApiFixture(): array
{
    $admin = rentalApiUser('admin');
    $agent = rentalApiUser('agent');

    $category = PropertyCategory::firstOrCreate(
        ['slug' => 'rental-api'],
        ['name' => 'Rental API', 'is_active' => true]
    );

    $property = Property::create([
        'reference' => 'PROP-' . uniqid(),
        'title' => 'Rental Test Property',
        'slug' => 'rental-test-' . uniqid(),
        'category_id' => $category->id,
        'listing_type' => 'rent',
        'asking_price' => 350000,
        'currency' => 'RWF',
        'agent_id' => $agent->id,
        'created_by' => $admin->id,
    ]);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
    ])->save();

    $customer = Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => 'Rental Test Tenant',
        'created_by' => $admin->id,
    ]);

    return [$admin, $agent, $property, $customer];
}

function rentalApiPayload(Property $property, Customer $customer): array
{
    return [
        'property_id' => $property->id,
        'customer_id' => $customer->id,
        'start_date' => '2026-12-01',
        'end_date' => '2027-11-30',
        'monthly_rent' => 350000,
        'security_deposit' => 700000,
        'payment_due_day' => 5,
    ];
}

test('rental endpoints require authentication', function () {
    $this->getJson('/api/rentals')->assertUnauthorized();
    $this->postJson('/api/rentals', [])->assertUnauthorized();
});

test('admin can register a draft rental agreement', function () {
    [$admin, $agent, $property, $customer] = rentalApiFixture();

    Sanctum::actingAs($admin);

    $this->postJson(
        '/api/rentals',
        rentalApiPayload($property, $customer)
    )
        ->assertCreated()
        ->assertJsonPath('rental.status', 'draft')
        ->assertJsonPath('rental.agent_id', $agent->id)
        ->assertJsonPath('rental.currency', 'RWF');

    expect(PropertyRental::count())->toBe(1);
    expect(PropertyRental::first()->statusHistory()->count())->toBe(1);
    expect($property->fresh()->availability_status)->toBe('available');
});

test('property manager can register a draft rental', function () {
    [$admin, $agent, $property, $customer] = rentalApiFixture();

    Sanctum::actingAs(rentalApiUser('property_manager'));

    $this->postJson(
        '/api/rentals',
        rentalApiPayload($property, $customer)
    )->assertCreated();
});

test('agent can view own rental but cannot create rental', function () {
    [$admin, $agent, $property, $customer] = rentalApiFixture();

    Sanctum::actingAs($agent);

    $this->postJson(
        '/api/rentals',
        rentalApiPayload($property, $customer)
    )->assertForbidden();

    Sanctum::actingAs($admin);

    $rentalId = $this->postJson(
        '/api/rentals',
        rentalApiPayload($property, $customer)
    )->assertCreated()->json('rental.id');

    Sanctum::actingAs($agent);

    $this->getJson("/api/rentals/{$rentalId}")
        ->assertOk()
        ->assertJsonPath('rental.id', $rentalId);

    $this->getJson('/api/rentals')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('customer cannot access staff rental agreements', function () {
    Sanctum::actingAs(rentalApiUser('customer'));

    $this->getJson('/api/rentals')->assertForbidden();
    $this->postJson('/api/rentals', [])->assertForbidden();
});

test('sold or unavailable property cannot be rented', function () {
    [$admin, $agent, $property, $customer] = rentalApiFixture();

    $property->forceFill([
        'availability_status' => 'sold',
        'is_published' => false,
    ])->save();

    Sanctum::actingAs($admin);

    $this->postJson(
        '/api/rentals',
        rentalApiPayload($property, $customer)
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_id');
});

test('sale listing cannot be used for a rental', function () {
    [$admin, $agent, $property, $customer] = rentalApiFixture();

    $property->update(['listing_type' => 'sale']);

    Sanctum::actingAs($admin);

    $this->postJson(
        '/api/rentals',
        rentalApiPayload($property, $customer)
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_id');
});

test('overlapping rental dates are rejected', function () {
    [$admin, $agent, $property, $customer] = rentalApiFixture();

    Sanctum::actingAs($admin);

    $payload = rentalApiPayload($property, $customer);

    $this->postJson('/api/rentals', $payload)
        ->assertCreated();

    $this->postJson('/api/rentals', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_date');

    expect(PropertyRental::count())->toBe(1);
});

test('admin can update a draft without changing its status', function () {
    [$admin, $agent, $property, $customer] = rentalApiFixture();

    Sanctum::actingAs($admin);

    $id = $this->postJson(
        '/api/rentals',
        rentalApiPayload($property, $customer)
    )->assertCreated()->json('rental.id');

    $this->patchJson("/api/rentals/{$id}", [
        'monthly_rent' => 375000,
        'payment_due_day' => 10,
        'notes' => 'Updated after negotiation.',
    ])
        ->assertOk()
        ->assertJsonPath('rental.status', 'draft')
        ->assertJsonPath('rental.payment_due_day', 10);

    expect(PropertyRental::findOrFail($id)->monthly_rent)
        ->toBe('375000.00');
});

test('invalid rent and lease dates are rejected', function () {
    [$admin, $agent, $property, $customer] = rentalApiFixture();

    Sanctum::actingAs($admin);

    $payload = rentalApiPayload($property, $customer);
    $payload['monthly_rent'] = -100;
    $payload['end_date'] = '2026-11-30';

    $this->postJson('/api/rentals', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'monthly_rent', 'end_date',
        ]);
});

test('agent cannot view a rental assigned to another agent', function () {
    [$admin, $agent, $property, $customer] = rentalApiFixture();

    Sanctum::actingAs($admin);

    $id = $this->postJson(
        '/api/rentals',
        rentalApiPayload($property, $customer)
    )->assertCreated()->json('rental.id');

    Sanctum::actingAs(rentalApiUser('agent'));

    $this->getJson("/api/rentals/{$id}")
        ->assertNotFound();

    $this->getJson('/api/rentals')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
