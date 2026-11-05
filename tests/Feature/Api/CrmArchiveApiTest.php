<?php

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function archiveTestUser(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function archiveTestCustomer(User $user): Customer
{
    return Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => 'CRM Archive Customer',
        'created_by' => $user->id,
    ]);
}

function archiveTestLead(
    User $user,
    Customer $customer
): Lead {
    return Lead::create([
        'reference' => 'LEAD-' . uniqid(),
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
        'created_by' => $user->id,
    ]);
}

test('archive endpoints require authentication', function () {
    $this->getJson('/api/customers/archived')
        ->assertUnauthorized();

    $this->getJson('/api/leads/archived')
        ->assertUnauthorized();

    $this->patchJson('/api/customers/1/archive', [])
        ->assertUnauthorized();

    $this->patchJson('/api/leads/1/restore', [])
        ->assertUnauthorized();
});

test('administrator can archive and restore customer', function () {
    $admin = archiveTestUser('super_admin');
    $customer = archiveTestCustomer($admin);

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/customers/{$customer->id}/archive",
        []
    )->assertOk();

    expect(Customer::find($customer->id))->toBeNull();

    expect(
        Customer::onlyTrashed()
            ->whereKey($customer->id)
            ->exists()
    )->toBeTrue();

    $this->getJson('/api/customers/archived')
        ->assertOk()
        ->assertJsonFragment([
            'reference' => $customer->reference,
        ]);

    $this->patchJson(
        "/api/customers/{$customer->id}/restore",
        []
    )->assertOk();

    expect(Customer::find($customer->id))->not->toBeNull();
});

test('customer with active leads cannot be archived', function () {
    $admin = archiveTestUser('super_admin');
    $customer = archiveTestCustomer($admin);

    archiveTestLead($admin, $customer);

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/customers/{$customer->id}/archive",
        []
    )->assertStatus(409);

    expect($customer->fresh()->trashed())->toBeFalse();
});

test('administrator can archive lead without deleting activities', function () {
    $admin = archiveTestUser('super_admin');
    $customer = archiveTestCustomer($admin);
    $lead = archiveTestLead($admin, $customer);

    $activity = LeadActivity::create([
        'lead_id' => $lead->id,
        'activity_type' => 'note',
        'description' => 'Customer contacted.',
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/leads/{$lead->id}/archive",
        []
    )->assertOk();

    expect(Lead::find($lead->id))->toBeNull();

    expect(
        Lead::onlyTrashed()->whereKey($lead->id)->exists()
    )->toBeTrue();

    expect(
        LeadActivity::whereKey($activity->id)->exists()
    )->toBeTrue();

    $this->getJson('/api/leads/archived')
        ->assertOk()
        ->assertJsonFragment([
            'reference' => $lead->reference,
        ]);

    $this->patchJson(
        "/api/leads/{$lead->id}/restore",
        []
    )->assertOk();

    expect(Lead::find($lead->id))->not->toBeNull();

    expect(
        LeadActivity::whereKey($activity->id)->exists()
    )->toBeTrue();
});

test('archived lead requires active customer before restoration', function () {
    $admin = archiveTestUser('super_admin');
    $customer = archiveTestCustomer($admin);
    $lead = archiveTestLead($admin, $customer);

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/leads/{$lead->id}/archive",
        []
    )->assertOk();

    $this->patchJson(
        "/api/customers/{$customer->id}/archive",
        []
    )->assertOk();

    $this->patchJson(
        "/api/leads/{$lead->id}/restore",
        []
    )->assertStatus(409);

    expect(Lead::onlyTrashed()->whereKey($lead->id)->exists())
        ->toBeTrue();

    $this->patchJson(
        "/api/customers/{$customer->id}/restore",
        []
    )->assertOk();

    $this->patchJson(
        "/api/leads/{$lead->id}/restore",
        []
    )->assertOk();
});

test('agent cannot archive or restore another agents customer', function () {
    $owner = archiveTestUser('agent');
    $other = archiveTestUser('agent');
    $customer = archiveTestCustomer($owner);

    Sanctum::actingAs($other);

    $this->patchJson(
        "/api/customers/{$customer->id}/archive",
        []
    )->assertNotFound();

    Sanctum::actingAs($owner);

    $this->patchJson(
        "/api/customers/{$customer->id}/archive",
        []
    )->assertOk();

    Sanctum::actingAs($other);

    $this->patchJson(
        "/api/customers/{$customer->id}/restore",
        []
    )->assertNotFound();

    $this->getJson('/api/customers/archived')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('agent cannot archive or restore unrelated leads', function () {
    $owner = archiveTestUser('agent');
    $other = archiveTestUser('agent');

    $customer = archiveTestCustomer($owner);
    $lead = archiveTestLead($owner, $customer);

    Sanctum::actingAs($other);

    $this->patchJson(
        "/api/leads/{$lead->id}/archive",
        []
    )->assertNotFound();

    Sanctum::actingAs($owner);

    $this->patchJson(
        "/api/leads/{$lead->id}/archive",
        []
    )->assertOk();

    Sanctum::actingAs($other);

    $this->patchJson(
        "/api/leads/{$lead->id}/restore",
        []
    )->assertNotFound();

    $this->getJson('/api/leads/archived')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('customer role cannot access archive operations', function () {
    $admin = archiveTestUser('super_admin');
    $customer = archiveTestCustomer($admin);
    $lead = archiveTestLead($admin, $customer);

    Sanctum::actingAs(archiveTestUser('customer'));

    $this->getJson('/api/customers/archived')
        ->assertForbidden();

    $this->getJson('/api/leads/archived')
        ->assertForbidden();

    $this->patchJson(
        "/api/customers/{$customer->id}/archive",
        []
    )->assertForbidden();

    $this->patchJson(
        "/api/leads/{$lead->id}/archive",
        []
    )->assertForbidden();
});
