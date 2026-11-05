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

function leadWorkflowUser(string $role, string $status = 'active'): User
{
    $user = User::factory()->create(['status' => $status]);
    $user->assignRole($role);

    return $user;
}

function workflowLead(User $creator): Lead
{
    $customer = Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => 'Workflow Customer',
        'created_by' => $creator->id,
    ]);

    return Lead::create([
        'reference' => 'LEAD-' . uniqid(),
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
        'created_by' => $creator->id,
    ]);
}

test('admin can assign lead to active agent', function () {
    $admin = leadWorkflowUser('super_admin');
    $agent = leadWorkflowUser('agent');
    $lead = workflowLead($admin);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/leads/{$lead->id}/assign", [
        'assigned_agent_id' => $agent->id,
    ])
        ->assertOk()
        ->assertJsonPath('lead.assigned_agent_id', $agent->id);

    expect($lead->fresh()->assigned_agent_id)->toBe($agent->id);

    $this->assertDatabaseHas('lead_activities', [
        'lead_id' => $lead->id,
        'activity_type' => 'assignment',
        'created_by' => $admin->id,
    ]);
});

test('agent cannot assign leads', function () {
    $admin = leadWorkflowUser('super_admin');
    $agent = leadWorkflowUser('agent');
    $lead = workflowLead($admin);

    Sanctum::actingAs($agent);

    $this->patchJson("/api/leads/{$lead->id}/assign", [
        'assigned_agent_id' => $agent->id,
    ])->assertForbidden();
});

test('inactive user cannot be assigned to lead', function () {
    $admin = leadWorkflowUser('super_admin');
    $inactive = leadWorkflowUser('agent', 'suspended');
    $lead = workflowLead($admin);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/leads/{$lead->id}/assign", [
        'assigned_agent_id' => $inactive->id,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assigned_agent_id');
});

test('admin can update lead pipeline status', function () {
    $admin = leadWorkflowUser('super_admin');
    $lead = workflowLead($admin);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/leads/{$lead->id}/status", [
        'status' => 'contacted',
        'notes' => 'Customer reached by phone.',
    ])
        ->assertOk()
        ->assertJsonPath('lead.status', 'contacted');

    $this->assertDatabaseHas('lead_activities', [
        'lead_id' => $lead->id,
        'activity_type' => 'status_change',
    ]);
});

test('closing and reopening lead updates closed timestamp', function () {
    $admin = leadWorkflowUser('super_admin');
    $lead = workflowLead($admin);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/leads/{$lead->id}/status", [
        'status' => 'won',
    ])->assertOk();

    expect($lead->fresh()->closed_at)->not->toBeNull();

    $this->patchJson("/api/leads/{$lead->id}/status", [
        'status' => 'qualified',
    ])->assertOk();

    expect($lead->fresh()->closed_at)->toBeNull();
});

test('agent cannot update unrelated lead status', function () {
    $admin = leadWorkflowUser('super_admin');
    $agent = leadWorkflowUser('agent');
    $lead = workflowLead($admin);

    Sanctum::actingAs($agent);

    $this->patchJson("/api/leads/{$lead->id}/status", [
        'status' => 'qualified',
    ])->assertForbidden();

    expect($lead->fresh()->status)->toBe('new');
});

test('authorized staff can view lead activity history', function () {
    $admin = leadWorkflowUser('super_admin');
    $lead = workflowLead($admin);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/leads/{$lead->id}/status", [
        'status' => 'contacted',
    ])->assertOk();

    $this->getJson("/api/leads/{$lead->id}/activities")
        ->assertOk()
        ->assertJsonCount(1, 'activities')
        ->assertJsonPath(
            'activities.0.activity_type',
            'status_change'
        );
});

test('agent cannot view unrelated lead activity history', function () {
    $admin = leadWorkflowUser('super_admin');
    $agent = leadWorkflowUser('agent');
    $lead = workflowLead($admin);

    Sanctum::actingAs($agent);

    $this->getJson("/api/leads/{$lead->id}/activities")
        ->assertForbidden();
});
