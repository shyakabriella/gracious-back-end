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

function activityUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function activityLead(User $creator): Lead
{
    $customer = Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => 'Activity Customer',
        'created_by' => $creator->id,
    ]);

    return Lead::create([
        'reference' => 'LEAD-' . uniqid(),
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
        'created_by' => $creator->id,
    ]);
}

test('activities require authentication', function () {
    $this->postJson('/api/leads/1/activities', [
        'activity_type' => 'note',
        'description' => 'Called customer.',
    ])->assertUnauthorized();
});

test('admin can record a call', function () {
    $admin = activityUser('super_admin');
    $lead = activityLead($admin);

    Sanctum::actingAs($admin);

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'call',
        'description' => 'Discussed property requirements.',
    ])
        ->assertCreated()
        ->assertJsonPath('activity.activity_type', 'call');

    $this->assertDatabaseHas('lead_activities', [
        'lead_id' => $lead->id,
        'activity_type' => 'call',
        'created_by' => $admin->id,
    ]);
});

test('assigned agent can record a lead note', function () {
    $admin = activityUser('super_admin');
    $agent = activityUser('agent');
    $lead = activityLead($admin);

    $lead->update(['assigned_agent_id' => $agent->id]);

    Sanctum::actingAs($agent);

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'note',
        'description' => 'Customer prefers a house in Kigali.',
    ])->assertCreated();
});

test('agent cannot record activities for another agents lead', function () {
    $first = activityUser('agent');
    $second = activityUser('agent');
    $lead = activityLead($first);

    Sanctum::actingAs($second);

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'note',
        'description' => 'Unauthorized note.',
    ])->assertNotFound();

    expect($lead->activities()->count())->toBe(0);
});

test('follow up scheduling updates next follow up date', function () {
    $admin = activityUser('super_admin');
    $lead = activityLead($admin);

    Sanctum::actingAs($admin);

    $date = now()->addDays(3)->startOfMinute();

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'follow_up',
        'description' => 'Call customer about house viewing.',
        'scheduled_at' => $date->toDateTimeString(),
    ])
        ->assertCreated()
        ->assertJsonPath('activity.activity_type', 'follow_up');

    expect(
        $lead->fresh()->next_follow_up_at->toDateTimeString()
    )->toBe($date->toDateTimeString());
});

test('follow up requires scheduled date', function () {
    $admin = activityUser('super_admin');
    $lead = activityLead($admin);

    Sanctum::actingAs($admin);

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'follow_up',
        'description' => 'Schedule customer call.',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_at');
});

test('customer role cannot create internal lead activity', function () {
    $admin = activityUser('super_admin');
    $lead = activityLead($admin);

    Sanctum::actingAs(activityUser('customer'));

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'note',
        'description' => 'Unauthorized activity.',
    ])->assertForbidden();
});

test('next follow-up uses earliest pending date regardless of creation order', function () {
    $admin = activityUser('super_admin');
    $lead = activityLead($admin);

    Sanctum::actingAs($admin);

    $early = now()->addDays(2)->startOfMinute();
    $late = now()->addDays(7)->startOfMinute();

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'follow_up',
        'description' => 'Earlier appointment',
        'scheduled_at' => $early->toDateTimeString(),
    ])->assertCreated();

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'follow_up',
        'description' => 'Later appointment',
        'scheduled_at' => $late->toDateTimeString(),
    ])->assertCreated();

    expect(
        $lead->fresh()->next_follow_up_at->toDateTimeString()
    )->toBe($early->toDateTimeString());
});

test('new follow-up is earlier than existing later appointment', function () {
    $admin = activityUser('super_admin');
    $lead = activityLead($admin);

    Sanctum::actingAs($admin);

    $late = now()->addDays(8)->startOfMinute();
    $early = now()->addDay()->startOfMinute();

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'follow_up',
        'description' => 'Later appointment',
        'scheduled_at' => $late->toDateTimeString(),
    ])->assertCreated();

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'follow_up',
        'description' => 'Earlier appointment',
        'scheduled_at' => $early->toDateTimeString(),
    ])->assertCreated();

    expect(
        $lead->fresh()->next_follow_up_at->toDateTimeString()
    )->toBe($early->toDateTimeString());
});

test('new scheduling ignores already completed follow-ups', function () {
    $admin = activityUser('super_admin');
    $lead = activityLead($admin);

    $lead->activities()->create([
        'activity_type' => 'follow_up',
        'description' => 'Completed appointment',
        'scheduled_at' => now()->addDay(),
        'completed_at' => now(),
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $next = now()->addDays(4)->startOfMinute();

    $this->postJson("/api/leads/{$lead->id}/activities", [
        'activity_type' => 'follow_up',
        'description' => 'Next pending appointment',
        'scheduled_at' => $next->toDateTimeString(),
    ])->assertCreated();

    expect(
        $lead->fresh()->next_follow_up_at->toDateTimeString()
    )->toBe($next->toDateTimeString());
});
