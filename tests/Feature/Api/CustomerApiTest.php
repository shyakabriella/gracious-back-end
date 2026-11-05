<?php

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function crmUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function crmCustomer(User $creator, string $name): Customer
{
    return Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => $name,
        'created_by' => $creator->id,
    ]);
}

test('customer routes require authentication', function () {
    $this->getJson('/api/customers')->assertUnauthorized();

    $this->postJson('/api/customers', [
        'name' => 'Test Customer',
    ])->assertUnauthorized();
});

test('admin can register a customer', function () {
    $admin = crmUser('super_admin');

    Sanctum::actingAs($admin);

    $this->postJson('/api/customers', [
        'name' => 'Alice Customer',
        'phone' => '0788000001',
        'customer_type' => 'buyer',
        'budget_min' => 10000000,
        'budget_max' => 50000000,
    ])
        ->assertCreated()
        ->assertJsonPath('customer.name', 'Alice Customer')
        ->assertJsonPath('customer.created_by', $admin->id);

    expect(Customer::count())->toBe(1);
    expect(Customer::first()->reference)->toStartWith('CUS-');
});

test('agent can register own customer', function () {
    $agent = crmUser('agent');

    Sanctum::actingAs($agent);

    $this->postJson('/api/customers', [
        'name' => 'Agent Customer',
        'customer_type' => 'tenant',
    ])
        ->assertCreated()
        ->assertJsonPath('customer.created_by', $agent->id);
});

test('customer role cannot access staff customer records', function () {
    Sanctum::actingAs(crmUser('customer'));

    $this->getJson('/api/customers')->assertForbidden();

    $this->postJson('/api/customers', [
        'name' => 'Unauthorized Customer',
    ])->assertForbidden();
});

test('agent cannot see another agents customer', function () {
    $first = crmUser('agent');
    $second = crmUser('agent');

    $otherCustomer = crmCustomer($first, 'Private Customer');

    Sanctum::actingAs($second);

    $this->getJson('/api/customers')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/customers/{$otherCustomer->id}")
        ->assertNotFound();
});

test('agent can update own customer but not another agents customer', function () {
    $first = crmUser('agent');
    $second = crmUser('agent');

    $customer = crmCustomer($first, 'Original Name');

    Sanctum::actingAs($first);

    $this->putJson("/api/customers/{$customer->id}", [
        'name' => 'Updated Name',
    ])
        ->assertOk()
        ->assertJsonPath('customer.name', 'Updated Name');

    Sanctum::actingAs($second);

    $this->putJson("/api/customers/{$customer->id}", [
        'name' => 'Unauthorized Update',
    ])->assertNotFound();

    expect($customer->fresh()->name)->toBe('Updated Name');
});

test('customer registration validates budget range', function () {
    Sanctum::actingAs(crmUser('super_admin'));

    $this->postJson('/api/customers', [
        'name' => 'Budget Customer',
        'budget_min' => 50000000,
        'budget_max' => 10000000,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('budget_max');
});

test('customer search does not expose other agents customers', function () {
    $first = crmUser('agent');
    $second = crmUser('agent');

    crmCustomer($first, 'Private Investor');
    crmCustomer($second, 'Own Buyer');

    Sanctum::actingAs($second);

    $this->getJson('/api/customers?search=Private')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
