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

function propertyTestUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function propertyPayload(int $categoryId): array
{
    return [
        'title' => 'Modern House in Kigali',
        'category_id' => $categoryId,
        'listing_type' => 'sale',
        'asking_price' => 150000000,
        'currency' => 'RWF',
        'district' => 'Gasabo',
        'sector' => 'Kimironko',
        'bedrooms' => 4,
        'bathrooms' => 3,
    ];
}

test('property routes require authentication', function () {
    $this->getJson('/api/properties')->assertUnauthorized();
    $this->postJson('/api/properties', [])->assertUnauthorized();
});

test('admin can register a property', function () {
    $admin = propertyTestUser('super_admin');

    Sanctum::actingAs($admin);

    $this->postJson('/api/properties', propertyPayload($this->category->id))
        ->assertCreated()
        ->assertJsonPath('property.reference', 'GI-000001')
        ->assertJsonPath('property.verification_status', 'draft')
        ->assertJsonPath('property.is_published', false);

    $this->assertDatabaseHas('properties', [
        'title' => 'Modern House in Kigali',
        'created_by' => $admin->id,
    ]);
});

test('agent can register own property', function () {
    $agent = propertyTestUser('agent');

    Sanctum::actingAs($agent);

    $this->postJson('/api/properties', propertyPayload($this->category->id))
        ->assertCreated()
        ->assertJsonPath('property.agent_id', $agent->id);
});

test('customer cannot register a property', function () {
    Sanctum::actingAs(propertyTestUser('customer'));

    $this->postJson('/api/properties', propertyPayload($this->category->id))
        ->assertForbidden();
});

test('invalid property information is rejected', function () {
    Sanctum::actingAs(propertyTestUser('super_admin'));

    $this->postJson('/api/properties', [
        'title' => 'Invalid Property',
        'category_id' => $this->category->id,
        'listing_type' => 'sale',
        'asking_price' => -500,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asking_price']);
});

test('agent cannot view another agent property', function () {
    $first = propertyTestUser('agent');
    $second = propertyTestUser('agent');

    Sanctum::actingAs($first);

    $this->postJson('/api/properties', propertyPayload($this->category->id))
        ->assertCreated();

    $property = Property::firstOrFail();

    Sanctum::actingAs($second);

    $this->getJson("/api/properties/{$property->id}")
        ->assertForbidden();

    $this->getJson('/api/properties')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('search filters do not expose another agent property', function () {
    $first = propertyTestUser('agent');
    $second = propertyTestUser('agent');

    Sanctum::actingAs($first);

    $this->postJson('/api/properties', propertyPayload($this->category->id))
        ->assertCreated();

    Sanctum::actingAs($second);

    $this->getJson('/api/properties?district=Gasabo')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('admin can update property details', function () {
    $admin = propertyTestUser('super_admin');

    Sanctum::actingAs($admin);

    $property = $this->postJson(
        '/api/properties',
        propertyPayload($this->category->id)
    )->json('property');

    $this->putJson("/api/properties/{$property['id']}", [
        'title' => 'Updated House in Kigali',
        'asking_price' => 160000000,
    ])
        ->assertOk()
        ->assertJsonPath('property.title', 'Updated House in Kigali');
});

test('property cannot be published through ordinary update', function () {
    $agent = propertyTestUser('agent');

    Sanctum::actingAs($agent);

    $property = $this->postJson(
        '/api/properties',
        propertyPayload($this->category->id)
    )->json('property');

    $this->putJson("/api/properties/{$property['id']}", [
        'is_published' => true,
        'verification_status' => 'verified',
    ])->assertOk();

    expect(Property::findOrFail($property['id'])->is_published)->toBeFalse();
    expect(Property::findOrFail($property['id'])->verification_status)->toBe('draft');
});

test('admin can archive property', function () {
    $admin = propertyTestUser('super_admin');

    Sanctum::actingAs($admin);

    $property = $this->postJson(
        '/api/properties',
        propertyPayload($this->category->id)
    )->json('property');

    $this->deleteJson("/api/properties/{$property['id']}")
        ->assertOk();

    expect(Property::count())->toBe(0);
    expect(Property::withTrashed()->count())->toBe(1);
});
