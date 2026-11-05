<?php

use App\Models\PropertyViewing;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function statusViewing($creator, $agent): PropertyViewing
{
    $lead = viewingApiLead($creator, $agent);

    return PropertyViewing::create([
        'reference' => 'VIEW-' . uniqid(),
        'lead_id' => $lead->id,
        'property_id' => $lead->property_id,
        'agent_id' => $agent->id,
        'scheduled_start_at' => now()->addDay(),
        'scheduled_end_at' => now()->addDay()->addHour(),
        'created_by' => $creator->id,
    ]);
}

test('admin can confirm requested viewing', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $viewing = statusViewing($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/viewings/{$viewing->id}/confirm")
        ->assertOk()
        ->assertJsonPath('viewing.status', 'confirmed');

    $this->assertDatabaseHas('property_viewing_status_histories', [
        'property_viewing_id' => $viewing->id,
        'from_status' => 'requested',
        'to_status' => 'confirmed',
        'changed_by' => $admin->id,
    ]);
});

test('assigned agent can confirm own viewing', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $viewing = statusViewing($admin, $agent);

    Sanctum::actingAs($agent);

    $this->patchJson("/api/viewings/{$viewing->id}/confirm")
        ->assertOk();

    expect($viewing->fresh()->status)->toBe('confirmed');
});

test('unrelated agent cannot change viewing', function () {
    $admin = viewingApiUser('super_admin');
    $assigned = viewingApiUser('agent');
    $other = viewingApiUser('agent');
    $viewing = statusViewing($admin, $assigned);

    Sanctum::actingAs($other);

    $this->patchJson("/api/viewings/{$viewing->id}/confirm")
        ->assertNotFound();

    $this->getJson("/api/viewings/{$viewing->id}/history")
        ->assertNotFound();
});

test('cancelling viewing requires a reason', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $viewing = statusViewing($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/viewings/{$viewing->id}/cancel", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->patchJson("/api/viewings/{$viewing->id}/cancel", [
        'reason' => 'Customer requested another date.',
    ])
        ->assertOk()
        ->assertJsonPath('viewing.status', 'cancelled');

    expect($viewing->fresh()->cancellation_reason)
        ->toBe('Customer requested another date.');
});

test('confirmed viewing can be completed after scheduled start', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $viewing = statusViewing($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/viewings/{$viewing->id}/confirm")
        ->assertOk();

    $this->travelTo($viewing->scheduled_start_at->copy()->addMinute());

    $this->patchJson("/api/viewings/{$viewing->id}/complete", [
        'notes' => 'Customer liked the property.',
    ])
        ->assertOk()
        ->assertJsonPath('viewing.status', 'completed');

    expect($viewing->fresh()->completed_at)->not->toBeNull();
});

test('viewing cannot be completed before scheduled start', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $viewing = statusViewing($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/viewings/{$viewing->id}/confirm")
        ->assertOk();

    $this->patchJson("/api/viewings/{$viewing->id}/complete")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('confirmed viewing can be marked no show after start', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $viewing = statusViewing($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/viewings/{$viewing->id}/confirm")
        ->assertOk();

    $this->travelTo($viewing->scheduled_start_at->copy()->addMinute());

    $this->patchJson("/api/viewings/{$viewing->id}/no-show")
        ->assertOk()
        ->assertJsonPath('viewing.status', 'no_show');
});

test('completed viewing cannot be cancelled or confirmed again', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $viewing = statusViewing($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/viewings/{$viewing->id}/confirm")
        ->assertOk();

    $this->travelTo($viewing->scheduled_start_at->copy()->addMinute());

    $this->patchJson("/api/viewings/{$viewing->id}/complete")
        ->assertOk();

    $this->patchJson("/api/viewings/{$viewing->id}/cancel", [
        'reason' => 'Trying to cancel completed viewing.',
    ])
        ->assertUnprocessable();

    $this->patchJson("/api/viewings/{$viewing->id}/confirm")
        ->assertUnprocessable();

    expect(
        DB::table('property_viewing_status_histories')
            ->where('property_viewing_id', $viewing->id)
            ->count()
    )->toBe(2);
});

test('authorized staff can view viewing status history', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $viewing = statusViewing($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/viewings/{$viewing->id}/confirm")
        ->assertOk();

    $this->getJson("/api/viewings/{$viewing->id}/history")
        ->assertOk()
        ->assertJsonCount(1, 'history')
        ->assertJsonPath('history.0.to_status', 'confirmed');
});

test('customer cannot access viewing status history', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $customer = viewingApiUser('customer');

    $viewing = statusViewing($admin, $agent);

    Sanctum::actingAs($customer);

    $this->getJson(
        "/api/viewings/{$viewing->id}/history"
    )->assertNotFound();
});

test('unauthenticated user cannot change viewing status', function () {
    $this->patchJson('/api/viewings/999/confirm')
        ->assertUnauthorized();

    $this->getJson('/api/viewings/999/history')
        ->assertUnauthorized();
});
