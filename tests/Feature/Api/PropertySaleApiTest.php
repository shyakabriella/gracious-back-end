<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertySale;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function saleApiUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function saleApiProperty(User $creator, User $agent): Property
{
    $category = PropertyCategory::firstOrCreate(
        ['slug' => 'sale-api'],
        ['name' => 'Sale API', 'is_active' => true]
    );

    $property = Property::create([
        'reference' => 'PROP-' . uniqid(),
        'title' => 'Sale API Property',
        'slug' => 'sale-api-' . uniqid(),
        'category_id' => $category->id,
        'listing_type' => 'sale',
        'asking_price' => 85000000,
        'currency' => 'RWF',
        'agent_id' => $agent->id,
        'created_by' => $creator->id,
    ]);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
    ])->save();

    return $property;
}

function saleApiCustomer(User $creator): Customer
{
    return Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => 'Sales Test Buyer',
        'created_by' => $creator->id,
    ]);
}

test('sales routes require authentication', function () {
    $this->getJson('/api/sales')->assertUnauthorized();
    $this->postJson('/api/sales', [])->assertUnauthorized();
});

test('admin can register a sale negotiation', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $property = saleApiProperty($admin, $agent);
    $buyer = saleApiCustomer($admin);

    Sanctum::actingAs($admin);

    $this->postJson('/api/sales', [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agent_id' => $agent->id,
        'agreed_price' => 82000000,
    ])
        ->assertCreated()
        ->assertJsonPath('sale.status', 'negotiation')
        ->assertJsonPath('sale.agent_id', $agent->id)
        ->assertJsonPath('sale.currency', 'RWF');

    expect(PropertySale::count())->toBe(1);
    expect(PropertySale::first()->statusHistory()->count())->toBe(1);
});

test('property cannot have two active sale negotiations', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $property = saleApiProperty($admin, $agent);
    $buyer = saleApiCustomer($admin);

    Sanctum::actingAs($admin);

    $payload = [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agent_id' => $agent->id,
        'agreed_price' => 82000000,
    ];

    $this->postJson('/api/sales', $payload)
        ->assertCreated();

    $this->postJson('/api/sales', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_id');

    expect(PropertySale::count())->toBe(1);
});

test('customer role cannot register a sale', function () {
    Sanctum::actingAs(saleApiUser('customer'));

    $this->postJson('/api/sales', [])
        ->assertForbidden();
});

test('rental property cannot be registered as a sale', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $property = saleApiProperty($admin, $agent);
    $buyer = saleApiCustomer($admin);

    $property->update(['listing_type' => 'rent']);

    Sanctum::actingAs($admin);

    $this->postJson('/api/sales', [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agent_id' => $agent->id,
        'agreed_price' => 82000000,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_id');

    expect(PropertySale::count())->toBe(0);
});

test('unpublished property cannot start a sale', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $property = saleApiProperty($admin, $agent);
    $buyer = saleApiCustomer($admin);

    $property->forceFill(['is_published' => false])->save();

    Sanctum::actingAs($admin);

    $this->postJson('/api/sales', [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agent_id' => $agent->id,
        'agreed_price' => 82000000,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_id');
});

test('sale creation requires a positive agreed price', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $property = saleApiProperty($admin, $agent);
    $buyer = saleApiCustomer($admin);

    Sanctum::actingAs($admin);

    $this->postJson('/api/sales', [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agent_id' => $agent->id,
        'agreed_price' => 0,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('agreed_price');
});

test('customer cannot set sale status or completion time', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $property = saleApiProperty($admin, $agent);
    $buyer = saleApiCustomer($admin);

    Sanctum::actingAs($admin);

    $this->postJson('/api/sales', [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agent_id' => $agent->id,
        'agreed_price' => 82000000,
        'status' => 'completed',
        'completed_at' => now()->toDateTimeString(),
        'currency' => 'USD',
        'asking_price' => 1,
    ])
        ->assertCreated()
        ->assertJsonPath('sale.status', 'negotiation')
        ->assertJsonPath('sale.currency', 'RWF')
        ->assertJsonPath('sale.asking_price', '85000000.00')
        ->assertJsonPath('sale.completed_at', null);
});

test('agent cannot see another agents sale', function () {
    $admin = saleApiUser('super_admin');
    $firstAgent = saleApiUser('agent');
    $secondAgent = saleApiUser('agent');

    $property = saleApiProperty($admin, $firstAgent);
    $buyer = saleApiCustomer($admin);

    Sanctum::actingAs($admin);

    $sale = $this->postJson('/api/sales', [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agent_id' => $firstAgent->id,
        'agreed_price' => 82000000,
    ])->assertCreated()->json('sale');

    Sanctum::actingAs($secondAgent);

    $this->getJson('/api/sales')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/sales/{$sale['id']}")
        ->assertNotFound();
});

test('assigned property agent can register own customer sale', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');

    $property = saleApiProperty($admin, $agent);
    $buyer = saleApiCustomer($agent);

    Sanctum::actingAs($agent);

    $this->postJson('/api/sales', [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agreed_price' => 82000000,
    ])
        ->assertCreated()
        ->assertJsonPath('sale.agent_id', $agent->id)
        ->assertJsonPath('sale.created_by', $agent->id);
});

test('agent cannot register sale for another agents property', function () {
    $admin = saleApiUser('super_admin');
    $assigned = saleApiUser('agent');
    $other = saleApiUser('agent');

    $property = saleApiProperty($admin, $assigned);
    $buyer = saleApiCustomer($other);

    Sanctum::actingAs($other);

    $this->postJson('/api/sales', [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agreed_price' => 82000000,
    ])->assertNotFound();

    expect(PropertySale::count())->toBe(0);
});

test('sale rejects lead belonging to another buyer', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');

    $property = saleApiProperty($admin, $agent);
    $buyer = saleApiCustomer($admin);
    $otherBuyer = saleApiCustomer($admin);

    $lead = \App\Models\Lead::create([
        'reference' => 'LEAD-' . uniqid(),
        'customer_id' => $otherBuyer->id,
        'property_id' => $property->id,
        'lead_type' => 'buy',
        'assigned_agent_id' => $agent->id,
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $this->postJson('/api/sales', [
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'lead_id' => $lead->id,
        'agent_id' => $agent->id,
        'agreed_price' => 82000000,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lead_id');

    expect(PropertySale::count())->toBe(0);
});
