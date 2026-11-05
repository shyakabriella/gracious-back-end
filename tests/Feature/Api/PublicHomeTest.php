<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['status' => 'active']);

    $this->category = PropertyCategory::create([
        'name' => 'House',
        'slug' => 'house',
        'is_active' => true,
    ]);
});

function homeProperty(User $user, int $categoryId, array $changes = []): Property
{
    $id = uniqid();

    $property = Property::create([
        'reference' => 'GI-' . $id,
        'title' => 'House in Kigali',
        'slug' => 'house-' . $id,
        'category_id' => $categoryId,
        'listing_type' => 'sale',
        'asking_price' => 45000000,
        'created_by' => $user->id,
    ]);

    $property->forceFill([
        'verification_status' => 'verified',
        'availability_status' => 'available',
        'is_published' => true,
        'verified_at' => now(),
        'published_at' => now(),
        ...$changes,
    ])->save();

    return $property->fresh();
}

test('public homepage works without authentication', function () {
    $this->getJson('/api/public/home')
        ->assertOk()
        ->assertJsonPath('statistics.total', 0)
        ->assertJsonPath('statistics.for_sale', 0)
        ->assertJsonPath('statistics.for_rent', 0)
        ->assertJsonCount(0, 'latest_properties');
});

test('homepage provides real property and category counts', function () {
    homeProperty($this->user, $this->category->id);

    homeProperty($this->user, $this->category->id, [
        'listing_type' => 'rent',
    ]);

    $this->getJson('/api/public/home')
        ->assertOk()
        ->assertJsonPath('statistics.total', 2)
        ->assertJsonPath('statistics.for_sale', 1)
        ->assertJsonPath('statistics.for_rent', 1)
        ->assertJsonPath('categories.0.property_count', 2)
        ->assertJsonCount(2, 'latest_properties')
        ->assertJsonCount(1, 'sale_properties')
        ->assertJsonCount(1, 'rental_properties');
});

test('homepage never counts unpublished or unavailable properties', function () {
    homeProperty($this->user, $this->category->id);

    homeProperty($this->user, $this->category->id, [
        'is_published' => false,
    ]);

    homeProperty($this->user, $this->category->id, [
        'availability_status' => 'sold',
    ]);

    homeProperty($this->user, $this->category->id, [
        'verification_status' => 'rejected',
    ]);

    $this->getJson('/api/public/home')
        ->assertOk()
        ->assertJsonPath('statistics.total', 1)
        ->assertJsonPath('categories.0.property_count', 1)
        ->assertJsonCount(1, 'latest_properties');
});

test('homepage never exposes internal property or owner fields', function () {
    homeProperty($this->user, $this->category->id);

    $response = $this->getJson('/api/public/home')
        ->assertOk()
        ->assertJsonCount(1, 'latest_properties');

    $response->assertJsonMissingPath('latest_properties.0.created_by');
    $response->assertJsonMissingPath('latest_properties.0.agent_id');
    $response->assertJsonMissingPath('latest_properties.0.owners');
    $response->assertJsonMissingPath('latest_properties.0.documents');
    $response->assertJsonMissingPath('latest_properties.0.verification_status');
});

test('public filters return active categories and listing types', function () {
    $this->getJson('/api/public/filters')
        ->assertOk()
        ->assertJsonPath('categories.0.slug', 'house')
        ->assertJsonPath('listing_types.0', 'sale')
        ->assertJsonPath('listing_types.1', 'rent');
});

test('public filters use only publicly available property locations', function () {
    homeProperty($this->user, $this->category->id, [
        'province' => 'Kigali',
        'district' => 'Gasabo',
        'sector' => 'Remera',
    ]);

    homeProperty($this->user, $this->category->id, [
        'province' => 'Eastern Province',
        'district' => 'Rwamagana',
        'sector' => 'Kigabiro',
        'is_published' => false,
    ]);

    $response = $this->getJson('/api/public/filters')
        ->assertOk()
        ->assertJsonPath('locations.provinces.0', 'Kigali')
        ->assertJsonPath('locations.districts.0', 'Gasabo')
        ->assertJsonPath('locations.sectors.0', 'Remera');

    $response->assertJsonMissing(['Rwamagana']);
    $response->assertJsonMissing(['Kigabiro']);
});

test('public filters provide categories and listing types', function () {
    $this->getJson('/api/public/filters')
        ->assertOk()
        ->assertJsonPath('categories.0.slug', 'house')
        ->assertJsonPath('listing_types.0', 'sale')
        ->assertJsonPath('listing_types.1', 'rent');
});

test('public filters exclude unavailable property locations', function () {
    homeProperty($this->user, $this->category->id, [
        'province' => 'Kigali',
        'district' => 'Gasabo',
        'sector' => 'Remera',
    ]);

    homeProperty($this->user, $this->category->id, [
        'province' => 'Eastern Province',
        'district' => 'Rwamagana',
        'sector' => 'Kigabiro',
        'is_published' => false,
    ]);

    $this->getJson('/api/public/filters')
        ->assertOk()
        ->assertJsonPath('locations.provinces.0', 'Kigali')
        ->assertJsonPath('locations.districts.0', 'Gasabo')
        ->assertJsonPath('locations.sectors.0', 'Remera')
        ->assertDontSee('Rwamagana')
        ->assertDontSee('Kigabiro');
});
