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

function inboxUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function inboxLead(User $creator, string $source = 'website'): Lead
{
    $customer = Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => 'Inbox Customer',
        'phone' => '0788000001',
        'created_by' => $creator->id,
    ]);

    return Lead::create([
        'reference' => 'LEAD-' . uniqid(),
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
        'source' => $source,
        'created_by' => $creator->id,
    ]);
}

test('unassigned leads inbox requires authentication', function () {
    $this->getJson('/api/crm/unassigned-leads')
        ->assertUnauthorized();
});

test('admin can view unassigned website leads', function () {
    $admin = inboxUser('super_admin');
    $lead = inboxLead($admin);

    Sanctum::actingAs($admin);

    $this->getJson('/api/crm/unassigned-leads?source=website')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $lead->id);
});

test('agent cannot access unassigned leads inbox', function () {
    Sanctum::actingAs(inboxUser('agent'));

    $this->getJson('/api/crm/unassigned-leads')
        ->assertForbidden();
});

test('customer cannot access unassigned leads inbox', function () {
    Sanctum::actingAs(inboxUser('customer'));

    $this->getJson('/api/crm/unassigned-leads')
        ->assertForbidden();
});

test('assigned leads disappear from unassigned inbox', function () {
    $admin = inboxUser('super_admin');
    $agent = inboxUser('agent');
    $lead = inboxLead($admin);

    Sanctum::actingAs($admin);

    $this->getJson('/api/crm/unassigned-leads')
        ->assertJsonCount(1, 'data');

    $this->patchJson("/api/leads/{$lead->id}/assign", [
        'assigned_agent_id' => $agent->id,
    ])->assertOk();

    $this->getJson('/api/crm/unassigned-leads')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('closed unassigned leads are not in active inbox', function () {
    $admin = inboxUser('super_admin');
    $lead = inboxLead($admin);

    $lead->update([
        'status' => 'lost',
        'closed_at' => now(),
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/crm/unassigned-leads')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('unassigned inbox supports customer search', function () {
    $admin = inboxUser('super_admin');

    $lead = inboxLead($admin);
    $lead->customer->update(['name' => 'Alice Property Buyer']);

    inboxLead($admin);

    Sanctum::actingAs($admin);

    $this->getJson('/api/crm/unassigned-leads?search=Alice')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $lead->id);
});
