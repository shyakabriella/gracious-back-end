<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['status' => 'active']);

    $this->category = PropertyCategory::create([
        'name' => 'House',
        'slug' => 'house',
    ]);
});

function websiteProperty(
    User $user,
    int $categoryId,
    array $changes = []
): Property {
    $unique = uniqid();

    $property = Property::create([
        'reference' => 'GI-' . $unique,
        'title' => 'House in Kigali',
        'slug' => 'house-' . $unique,
        'category_id' => $categoryId,
        'listing_type' => 'sale',
        'asking_price' => 50000000,
        'district' => 'Gasabo',
        'bedrooms' => 3,
        'bathrooms' => 2,
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

test('public property search filters by district and price', function () {
    websiteProperty($this->user, $this->category->id);

    websiteProperty($this->user, $this->category->id, [
        'district' => 'Kicukiro',
        'asking_price' => 90000000,
    ]);

    $this->getJson(
        '/api/public/properties?district=Gasabo&min_price=40000000&max_price=60000000'
    )
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.district', 'Gasabo');
});

test('public property search filters bedrooms', function () {
    websiteProperty($this->user, $this->category->id);

    websiteProperty($this->user, $this->category->id, [
        'bedrooms' => 5,
    ]);

    $this->getJson('/api/public/properties?min_bedrooms=4')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.bedrooms', 5);
});

test('public property search sorts by price', function () {
    websiteProperty($this->user, $this->category->id, [
        'asking_price' => 70000000,
    ]);

    websiteProperty($this->user, $this->category->id, [
        'asking_price' => 30000000,
    ]);

    $response = $this->getJson('/api/public/properties?sort=price_asc')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(
        (float) $response->json('data.0.asking_price')
    )->toBe(30000000.0);
});

test('unpublished properties remain hidden from public search', function () {
    websiteProperty($this->user, $this->category->id);

    websiteProperty($this->user, $this->category->id, [
        'is_published' => false,
    ]);

    $this->getJson('/api/public/properties')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('public details contain safe gallery links', function () {
    $property = websiteProperty($this->user, $this->category->id);

    $image = PropertyImage::create([
        'property_id' => $property->id,
        'file_path' => 'properties/private-image.jpg',
        'original_name' => 'house.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1000,
        'is_cover' => true,
        'sort_order' => 1,
        'uploaded_by' => $this->user->id,
    ]);

    $response = $this->getJson(
        "/api/public/properties/{$property->slug}"
    )
        ->assertOk()
        ->assertJsonCount(1, 'property.images')
        ->assertJsonPath('property.cover_image.id', $image->id)
        ->assertJsonMissingPath('property.agent_id')
        ->assertJsonMissingPath('property.created_by');

    $response->assertDontSee('properties/private-image.jpg');
    $response->assertDontSee('uploaded_by');
});

test('invalid public property filters are rejected', function () {
    $this->getJson('/api/public/properties?sort=invalid')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sort');

    $this->getJson('/api/public/properties?min_price=900&max_price=100')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_price');
});
