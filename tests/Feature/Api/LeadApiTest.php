<?php

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function leadUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function leadCustomer(User $user): Customer
{
    return Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => 'Customer ' . $user->id,
        'created_by' => $user->id,
    ]);
}

function makeLead(User $user, Customer $customer): Lead
{
    return Lead::create([
        'reference' => 'LEAD-' . uniqid(),
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
        'created_by' => $user->id,
        'assigned_agent_id' => $user->hasRole('agent')
            ? $user->id
            : null,
    ]);
}

test('lead routes require authentication', function () {
    $this->getJson('/api/leads')->assertUnauthorized();

    $this->postJson('/api/leads', [
        'customer_id' => 1,
        'lead_type' => 'buy',
    ])->assertUnauthorized();
});

test('admin can register a lead', function () {
    $admin = leadUser('super_admin');
    $customer = leadCustomer($admin);

    Sanctum::actingAs($admin);

    $this->postJson('/api/leads', [
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
        'source' => 'phone',
        'subject' => 'Looking for a house',
    ])
        ->assertCreated()
        ->assertJsonPath('lead.status', 'new')
        ->assertJsonPath('lead.customer_id', $customer->id)
        ->assertJsonPath('lead.source', 'phone');

    expect(Lead::count())->toBe(1);
    expect(Lead::first()->reference)->toStartWith('LEAD-');
});

test('agent can register lead for own customer', function () {
    $agent = leadUser('agent');
    $customer = leadCustomer($agent);

    Sanctum::actingAs($agent);

    $this->postJson('/api/leads', [
        'customer_id' => $customer->id,
        'lead_type' => 'rent',
    ])
        ->assertCreated()
        ->assertJsonPath('lead.assigned_agent_id', $agent->id);
});

test('agent cannot create lead for another agents customer', function () {
    $first = leadUser('agent');
    $second = leadUser('agent');
    $customer = leadCustomer($first);

    Sanctum::actingAs($second);

    $this->postJson('/api/leads', [
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('customer_id');

    expect(Lead::count())->toBe(0);
});

test('agent cannot view another agents lead', function () {
    $first = leadUser('agent');
    $second = leadUser('agent');

    $lead = makeLead($first, leadCustomer($first));

    Sanctum::actingAs($second);

    $this->getJson('/api/leads')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/leads/{$lead->id}")
        ->assertNotFound();
});

test('agent can view assigned lead', function () {
    $admin = leadUser('super_admin');
    $agent = leadUser('agent');

    $lead = makeLead($admin, leadCustomer($admin));

    $lead->update([
        'assigned_agent_id' => $agent->id,
    ]);

    Sanctum::actingAs($agent);

    $this->getJson("/api/leads/{$lead->id}")
        ->assertOk()
        ->assertJsonPath('lead.id', $lead->id);
});

test('customer role cannot access internal leads', function () {
    Sanctum::actingAs(leadUser('customer'));

    $this->getJson('/api/leads')->assertForbidden();

    $this->postJson('/api/leads', [
        'customer_id' => 1,
        'lead_type' => 'buy',
    ])->assertForbidden();
});

test('lead creation cannot set internal status or assigned agent', function () {
    $admin = leadUser('super_admin');
    $agent = leadUser('agent');
    $customer = leadCustomer($admin);

    Sanctum::actingAs($admin);

    $this->postJson('/api/leads', [
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
        'status' => 'won',
        'assigned_agent_id' => $agent->id,
    ])
        ->assertCreated()
        ->assertJsonPath('lead.status', 'new')
        ->assertJsonPath('lead.assigned_agent_id', null);
});
