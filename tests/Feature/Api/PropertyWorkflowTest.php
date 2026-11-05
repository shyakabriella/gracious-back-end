<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->category = PropertyCategory::create([
        'name' => 'House',
        'slug' => 'house',
        'is_active' => true,
    ]);
});

function workflowUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function workflowProperty(User $creator, int $categoryId): Property
{
    return Property::create([
        'reference' => 'GI-' . uniqid(),
        'title' => 'House in Kigali',
        'slug' => 'house-' . uniqid(),
        'category_id' => $categoryId,
        'listing_type' => 'sale',
        'asking_price' => 100000000,
        'district' => 'Gasabo',
        'created_by' => $creator->id,
    ]);
}

test('agent can submit own draft property', function () {
    $agent = workflowUser('agent');
    $property = workflowProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->postJson("/api/properties/{$property->id}/submit")
        ->assertOk()
        ->assertJsonPath('property.verification_status', 'pending');
});

test('agent cannot verify a property', function () {
    $agent = workflowUser('agent');
    $property = workflowProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->patchJson("/api/properties/{$property->id}/verification", [
        'status' => 'verified',
    ])->assertForbidden();
});

test('admin can verify a pending property', function () {
    $agent = workflowUser('agent');
    $admin = workflowUser('super_admin');
    $property = workflowProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->postJson("/api/properties/{$property->id}/submit")
        ->assertOk();

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/verification", [
        'status' => 'verified',
    ])
        ->assertOk()
        ->assertJsonPath('property.verification_status', 'verified');

    expect($property->fresh()->verified_at)->not->toBeNull();
});

test('draft property cannot be published', function () {
    $admin = workflowUser('super_admin');
    $property = workflowProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/publish", [
        'is_published' => true,
    ])->assertUnprocessable();
});

test('verified property can be published', function () {
    $admin = workflowUser('super_admin');
    $property = workflowProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $this->postJson("/api/properties/{$property->id}/submit")
        ->assertOk();

    $this->patchJson("/api/properties/{$property->id}/verification", [
        'status' => 'verified',
    ])->assertOk();

    $this->patchJson("/api/properties/{$property->id}/publish", [
        'is_published' => true,
    ])
        ->assertOk()
        ->assertJsonPath('property.is_published', true);

    expect($property->fresh()->published_at)->not->toBeNull();
});

test('agent cannot publish property', function () {
    $agent = workflowUser('agent');
    $property = workflowProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->patchJson("/api/properties/{$property->id}/publish", [
        'is_published' => true,
    ])->assertForbidden();
});

test('reserving property removes it from publication', function () {
    $admin = workflowUser('super_admin');
    $property = workflowProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'verified_at' => now(),
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/availability", [
        'status' => 'reserved',
    ])
        ->assertOk()
        ->assertJsonPath('property.is_published', false);

    expect($property->fresh()->availability_status)->toBe('reserved');
});

test('agent cannot mark a property as sold', function () {
    $agent = workflowUser('agent');
    $property = workflowProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->patchJson("/api/properties/{$property->id}/availability", [
        'status' => 'sold',
    ])->assertForbidden();
});

test('admin can assign active agent', function () {
    $admin = workflowUser('super_admin');
    $agent = workflowUser('agent');
    $property = workflowProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/assign-agent", [
        'agent_id' => $agent->id,
    ])
        ->assertOk()
        ->assertJsonPath('property.agent_id', $agent->id);
});

test('public listing only shows approved published available property', function () {
    $admin = workflowUser('super_admin');

    $published = workflowProperty($admin, $this->category->id);

    $published->forceFill([
        'verification_status' => 'verified',
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    workflowProperty($admin, $this->category->id);

    auth()->forgetGuards();

    $this->getJson('/api/public/properties')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.created_by')
        ->assertJsonMissingPath('data.0.agent_id');

    $this->getJson('/api/public/properties/' . $published->slug)
        ->assertOk()
        ->assertJsonPath('property.reference', $published->reference);
});

test('rejected and reserved properties are hidden publicly', function () {
    $admin = workflowUser('super_admin');
    $property = workflowProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'reserved',
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    $this->getJson('/api/public/properties')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson('/api/public/properties/' . $property->slug)
        ->assertNotFound();
});

test('admin verification records reviewer and decision', function () {
    $admin = workflowUser('super_admin');
    $agent = workflowUser('agent');
    $property = workflowProperty($agent, $this->category->id);

    Sanctum::actingAs($admin);

    $this->postJson("/api/properties/{$property->id}/submit")
        ->assertOk();

    $this->patchJson("/api/properties/{$property->id}/verification", [
        'status' => 'verified',
    ])->assertOk();

    $this->assertDatabaseHas('property_verification_histories', [
        'property_id' => $property->id,
        'reviewed_by' => $admin->id,
        'decision' => 'verified',
    ]);
});

test('rejecting a property requires a reason', function () {
    $admin = workflowUser('super_admin');
    $property = workflowProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $this->postJson("/api/properties/{$property->id}/submit")
        ->assertOk();

    $this->patchJson("/api/properties/{$property->id}/verification", [
        'status' => 'rejected',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    expect($property->fresh()->verification_status)->toBe('pending');
});

test('admin can reject property and review history', function () {
    $admin = workflowUser('super_admin');
    $property = workflowProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $this->postJson("/api/properties/{$property->id}/submit")
        ->assertOk();

    $this->patchJson("/api/properties/{$property->id}/verification", [
        'status' => 'rejected',
        'reason' => 'Ownership document needs correction.',
    ])->assertOk();

    $this->getJson(
        "/api/properties/{$property->id}/verification-history"
    )
        ->assertOk()
        ->assertJsonCount(1, 'history')
        ->assertJsonPath('history.0.decision', 'rejected')
        ->assertJsonPath(
            'history.0.reason',
            'Ownership document needs correction.'
        )
        ->assertJsonPath('history.0.reviewer.id', $admin->id);
});

test('agent cannot access verification history', function () {
    $agent = workflowUser('agent');
    $property = workflowProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->getJson(
        "/api/properties/{$property->id}/verification-history"
    )->assertForbidden();
});
