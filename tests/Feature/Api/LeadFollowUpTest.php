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

function followUpUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function followUpLead(User $creator): Lead
{
    $customer = Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => 'Follow-up Customer',
        'created_by' => $creator->id,
    ]);

    return Lead::create([
        'reference' => 'LEAD-' . uniqid(),
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
        'created_by' => $creator->id,
    ]);
}

test('follow-up list requires authentication', function () {
    $this->getJson('/api/crm/follow-ups')->assertUnauthorized();
});

test('admin can see pending follow-ups', function () {
    $admin = followUpUser('super_admin');
    $lead = followUpLead($admin);

    $lead->activities()->create([
        'activity_type' => 'follow_up',
        'description' => 'Call tomorrow',
        'scheduled_at' => now()->addDay(),
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/crm/follow-ups')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('agent cannot see unrelated follow-ups', function () {
    $first = followUpUser('agent');
    $second = followUpUser('agent');

    $lead = followUpLead($first);

    $lead->activities()->create([
        'activity_type' => 'follow_up',
        'description' => 'Private follow-up',
        'scheduled_at' => now()->addDay(),
        'created_by' => $first->id,
    ]);

    Sanctum::actingAs($second);

    $this->getJson('/api/crm/follow-ups')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('upcoming and overdue filters separate pending follow-ups', function () {
    $admin = followUpUser('super_admin');
    $lead = followUpLead($admin);

    $lead->activities()->create([
        'activity_type' => 'follow_up',
        'description' => 'Overdue call',
        'scheduled_at' => now()->subDay(),
        'created_by' => $admin->id,
    ]);

    $lead->activities()->create([
        'activity_type' => 'follow_up',
        'description' => 'Upcoming call',
        'scheduled_at' => now()->addDay(),
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/crm/follow-ups?filter=overdue')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.description', 'Overdue call');

    $this->getJson('/api/crm/follow-ups?filter=upcoming')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.description', 'Upcoming call');
});

test('completing follow-up recalculates next pending date', function () {
    $admin = followUpUser('super_admin');
    $lead = followUpLead($admin);

    $firstDate = now()->addDay()->startOfSecond();
    $secondDate = now()->addDays(3)->startOfSecond();

    $first = $lead->activities()->create([
        'activity_type' => 'follow_up',
        'description' => 'First call',
        'scheduled_at' => $firstDate,
        'created_by' => $admin->id,
    ]);

    $lead->activities()->create([
        'activity_type' => 'follow_up',
        'description' => 'Second call',
        'scheduled_at' => $secondDate,
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/leads/{$lead->id}/activities/{$first->id}/complete"
    )
        ->assertOk()
        ->assertJsonPath('activity.id', $first->id);

    expect($first->fresh()->completed_at)->not->toBeNull();

    expect(
        $lead->fresh()->next_follow_up_at->toDateTimeString()
    )->toBe($secondDate->toDateTimeString());

    $this->getJson('/api/crm/follow-ups')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('agent cannot complete unrelated follow-up', function () {
    $first = followUpUser('agent');
    $second = followUpUser('agent');
    $lead = followUpLead($first);

    $activity = $lead->activities()->create([
        'activity_type' => 'follow_up',
        'description' => 'Private follow-up',
        'scheduled_at' => now()->addDay(),
        'created_by' => $first->id,
    ]);

    Sanctum::actingAs($second);

    $this->patchJson(
        "/api/leads/{$lead->id}/activities/{$activity->id}/complete"
    )->assertNotFound();

    expect($activity->fresh()->completed_at)->toBeNull();
});

test('activity from another lead cannot be completed', function () {
    $admin = followUpUser('super_admin');

    $lead = followUpLead($admin);
    $other = followUpLead($admin);

    $activity = $other->activities()->create([
        'activity_type' => 'follow_up',
        'description' => 'Other lead call',
        'scheduled_at' => now()->addDay(),
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/leads/{$lead->id}/activities/{$activity->id}/complete"
    )->assertNotFound();
});
