<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyOwner;
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

function ownershipUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function ownershipProperty(User $user, int $categoryId): Property
{
    return Property::create([
        'reference' => 'GI-' . uniqid(),
        'title' => 'House in Kigali',
        'slug' => 'house-' . uniqid(),
        'category_id' => $categoryId,
        'listing_type' => 'sale',
        'asking_price' => 50000000,
        'created_by' => $user->id,
    ]);
}

function ownershipOwner(User $user): PropertyOwner
{
    return PropertyOwner::create([
        'reference' => 'OWN-' . uniqid(),
        'name' => 'Property Owner',
        'created_by' => $user->id,
    ]);
}

test('admin can link owner to property', function () {
    $admin = ownershipUser('super_admin');
    $property = ownershipProperty($admin, $this->category->id);
    $owner = ownershipOwner($admin);

    Sanctum::actingAs($admin);

    $this->postJson("/api/properties/{$property->id}/owners", [
        'owner_id' => $owner->id,
    ])->assertCreated();

    $this->assertDatabaseHas('property_owner', [
        'property_id' => $property->id,
        'property_owner_id' => $owner->id,
    ]);
});

test('one property can have multiple owners', function () {
    $admin = ownershipUser('super_admin');
    $property = ownershipProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    foreach ([ownershipOwner($admin), ownershipOwner($admin)] as $owner) {
        $this->postJson("/api/properties/{$property->id}/owners", [
            'owner_id' => $owner->id,
        ])->assertCreated();
    }

    $this->getJson("/api/properties/{$property->id}/owners")
        ->assertOk()
        ->assertJsonCount(2, 'owners');
});

test('duplicate ownership link is not created', function () {
    $admin = ownershipUser('super_admin');
    $property = ownershipProperty($admin, $this->category->id);
    $owner = ownershipOwner($admin);

    Sanctum::actingAs($admin);

    $url = "/api/properties/{$property->id}/owners";

    $this->postJson($url, ['owner_id' => $owner->id])
        ->assertCreated();

    $this->postJson($url, ['owner_id' => $owner->id])
        ->assertOk();

    expect($property->owners()->count())->toBe(1);
});

test('agent can link own owner to own property', function () {
    $agent = ownershipUser('agent');
    $property = ownershipProperty($agent, $this->category->id);
    $owner = ownershipOwner($agent);

    Sanctum::actingAs($agent);

    $this->postJson("/api/properties/{$property->id}/owners", [
        'owner_id' => $owner->id,
    ])->assertCreated();
});

test('agent cannot link owner belonging to another agent', function () {
    $first = ownershipUser('agent');
    $second = ownershipUser('agent');

    $property = ownershipProperty($first, $this->category->id);
    $owner = ownershipOwner($second);

    Sanctum::actingAs($first);

    $this->postJson("/api/properties/{$property->id}/owners", [
        'owner_id' => $owner->id,
    ])->assertNotFound();

    expect($property->owners()->count())->toBe(0);
});

test('agent cannot change another agents property ownership', function () {
    $first = ownershipUser('agent');
    $second = ownershipUser('agent');

    $property = ownershipProperty($first, $this->category->id);
    $owner = ownershipOwner($second);

    Sanctum::actingAs($second);

    $this->postJson("/api/properties/{$property->id}/owners", [
        'owner_id' => $owner->id,
    ])->assertForbidden();
});

test('admin can remove ownership link without deleting owner', function () {
    $admin = ownershipUser('super_admin');
    $property = ownershipProperty($admin, $this->category->id);
    $owner = ownershipOwner($admin);

    $property->owners()->attach($owner->id);

    Sanctum::actingAs($admin);

    $this->deleteJson(
        "/api/properties/{$property->id}/owners/{$owner->id}"
    )->assertOk();

    expect($property->owners()->count())->toBe(0);
    expect(PropertyOwner::find($owner->id))->not->toBeNull();
});

test('customer cannot access property owners', function () {
    $admin = ownershipUser('super_admin');
    $property = ownershipProperty($admin, $this->category->id);

    Sanctum::actingAs(ownershipUser('customer'));

    $this->getJson(
        "/api/properties/{$property->id}/owners"
    )->assertForbidden();
});

test('admin can list all properties belonging to an owner', function () {
    $admin = ownershipUser('super_admin');
    $owner = ownershipOwner($admin);

    $first = ownershipProperty($admin, $this->category->id);
    $second = ownershipProperty($admin, $this->category->id);

    $owner->properties()->attach([$first->id, $second->id]);

    Sanctum::actingAs($admin);

    $this->getJson("/api/owners/{$owner->id}/properties")
        ->assertOk()
        ->assertJsonCount(2, 'properties')
        ->assertJsonPath('owner_id', $owner->id);
});

test('agent only sees their accessible properties for a shared owner', function () {
    $admin = ownershipUser('super_admin');
    $firstAgent = ownershipUser('agent');
    $secondAgent = ownershipUser('agent');

    $owner = ownershipOwner($admin);

    $first = ownershipProperty($firstAgent, $this->category->id);
    $second = ownershipProperty($secondAgent, $this->category->id);

    $owner->properties()->attach([$first->id, $second->id]);

    Sanctum::actingAs($firstAgent);

    $this->getJson("/api/owners/{$owner->id}/properties")
        ->assertOk()
        ->assertJsonCount(1, 'properties')
        ->assertJsonPath('properties.0.id', $first->id)
        ->assertJsonMissing(['id' => $second->id]);
});

test('owner linked to a property cannot be archived', function () {
    $admin = ownershipUser('super_admin');
    $owner = ownershipOwner($admin);
    $property = ownershipProperty($admin, $this->category->id);

    $owner->properties()->attach($property->id);

    Sanctum::actingAs($admin);

    $this->deleteJson("/api/owners/{$owner->id}")
        ->assertUnprocessable();

    expect($owner->fresh())->not->toBeNull();
    expect($property->owners()->count())->toBe(1);
});

test('public property details do not expose owner contacts', function () {
    $admin = ownershipUser('super_admin');
    $owner = ownershipOwner($admin);
    $property = ownershipProperty($admin, $this->category->id);

    $owner->update([
        'phone' => '0789876543',
        'email' => 'private-owner@example.com',
        'notes' => 'Confidential owner instructions',
    ]);

    $property->owners()->attach($owner->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'verified_at' => now(),
        'availability_status' => 'available',
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    $this->getJson("/api/public/properties/{$property->slug}")
        ->assertOk()
        ->assertDontSee('0789876543')
        ->assertDontSee('private-owner@example.com')
        ->assertDontSee('Confidential owner instructions');
});
