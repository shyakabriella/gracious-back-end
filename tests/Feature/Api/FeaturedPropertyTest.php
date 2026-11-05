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
    ]);
});

function featuredUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function featuredProperty(User $user, int $categoryId): Property
{
    $id = uniqid();

    return Property::create([
        'reference' => 'GI-' . $id,
        'title' => 'House in Kigali',
        'slug' => 'house-' . $id,
        'category_id' => $categoryId,
        'listing_type' => 'sale',
        'asking_price' => 50000000,
        'created_by' => $user->id,
    ]);
}

test('admin can feature verified published property', function () {
    $admin = featuredUser('super_admin');
    $property = featuredProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/featured", [
        'is_featured' => true,
    ])
        ->assertOk()
        ->assertJsonPath('is_featured', true);

    expect((bool) $property->fresh()->is_featured)->toBeTrue();
});

test('draft property cannot be featured', function () {
    $admin = featuredUser('super_admin');
    $property = featuredProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/featured", [
        'is_featured' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_featured');
});

test('agent cannot feature property', function () {
    $agent = featuredUser('agent');
    $property = featuredProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->patchJson("/api/properties/{$property->id}/featured", [
        'is_featured' => true,
    ])->assertForbidden();
});

test('public homepage returns only eligible featured properties', function () {
    $admin = featuredUser('super_admin');

    $visible = featuredProperty($admin, $this->category->id);
    $hidden = featuredProperty($admin, $this->category->id);

    $visible->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'is_featured' => true,
        'published_at' => now(),
    ])->save();

    $hidden->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'sold',
        'is_published' => true,
        'is_featured' => true,
        'published_at' => now(),
    ])->save();

    $this->getJson('/api/public/home')
        ->assertOk()
        ->assertJsonCount(1, 'featured_properties')
        ->assertJsonPath('featured_properties.0.slug', $visible->slug);
});

test('admin can remove featured status', function () {
    $admin = featuredUser('super_admin');
    $property = featuredProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'is_featured' => true,
    ])->save();

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/featured", [
        'is_featured' => false,
    ])
        ->assertOk()
        ->assertJsonPath('is_featured', false);
});

test('reserving featured property clears featured status', function () {
    $admin = featuredUser('super_admin');
    $property = featuredProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'is_featured' => true,
        'published_at' => now(),
    ])->save();

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/availability", [
        'status' => 'reserved',
    ])->assertOk();

    expect($property->fresh()->is_featured)->toBeFalse();
    expect($property->fresh()->is_published)->toBeFalse();
});

test('selling featured property clears featured status', function () {
    $admin = featuredUser('super_admin');
    $property = featuredProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'is_featured' => true,
    ])->save();

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/availability", [
        'status' => 'sold',
    ])->assertOk();

    expect($property->fresh()->is_featured)->toBeFalse();
});

test('unpublishing property clears featured status', function () {
    $admin = featuredUser('super_admin');
    $property = featuredProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'is_featured' => true,
    ])->save();

    Sanctum::actingAs($admin);

    $this->patchJson("/api/properties/{$property->id}/publish", [
        'is_published' => false,
    ])->assertOk();

    expect($property->fresh()->is_featured)->toBeFalse();
});

test('editing featured property requires review and removes featured status', function () {
    $admin = featuredUser('super_admin');
    $property = featuredProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'is_featured' => true,
    ])->save();

    Sanctum::actingAs($admin);

    $this->putJson("/api/properties/{$property->id}", [
        'title' => 'Updated House in Kigali',
    ])->assertOk();

    $property->refresh();

    expect($property->verification_status)->toBe('pending');
    expect($property->is_published)->toBeFalse();
    expect($property->is_featured)->toBeFalse();
});

test('archiving featured property clears featured status', function () {
    $admin = featuredUser('super_admin');
    $property = featuredProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'is_featured' => true,
    ])->save();

    Sanctum::actingAs($admin);

    $this->deleteJson("/api/properties/{$property->id}")
        ->assertOk();

    $archived = Property::withTrashed()->findOrFail($property->id);

    expect($archived->trashed())->toBeTrue();
    expect($archived->is_featured)->toBeFalse();
});

test('agent cannot feature property through ordinary update', function () {
    $agent = featuredUser('agent');
    $property = featuredProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->putJson("/api/properties/{$property->id}", [
        'is_featured' => true,
    ])->assertOk();

    expect($property->fresh()->is_featured)->toBeFalse();
});

test('admin cannot feature property through ordinary update', function () {
    $admin = featuredUser('super_admin');
    $property = featuredProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $this->putJson("/api/properties/{$property->id}", [
        'is_featured' => true,
    ])->assertOk();

    expect($property->fresh()->is_featured)->toBeFalse();
});
