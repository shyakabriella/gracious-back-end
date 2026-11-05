<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyImage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->seed(RolePermissionSeeder::class);

    $this->category = PropertyCategory::create([
        'name' => 'House',
        'slug' => 'house',
    ]);
});

function imageTestUser(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function imageTestProperty(User $user, int $categoryId): Property
{
    return Property::create([
        'reference' => 'GI-' . uniqid(),
        'title' => 'Modern House',
        'slug' => 'modern-house-' . uniqid(),
        'category_id' => $categoryId,
        'listing_type' => 'sale',
        'asking_price' => 85000000,
        'created_by' => $user->id,
    ]);
}

test('agent can upload an image to own property', function () {
    $agent = imageTestUser('agent');
    $property = imageTestProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('house.jpg')]
    )
        ->assertCreated()
        ->assertJsonPath('image.is_cover', true);

    expect(PropertyImage::count())->toBe(1);

    Storage::disk('local')->assertExists(
        PropertyImage::firstOrFail()->file_path
    );
});

test('second image is not automatically the cover', function () {
    $agent = imageTestUser('agent');
    $property = imageTestProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    foreach (['first.jpg', 'second.jpg'] as $name) {
        $this->postJson(
            "/api/properties/{$property->id}/images",
            ['image' => UploadedFile::fake()->image($name)]
        )->assertCreated();
    }

    expect(PropertyImage::where('is_cover', true)->count())->toBe(1);
    expect(PropertyImage::count())->toBe(2);
});

test('another agent cannot upload images', function () {
    $owner = imageTestUser('agent');
    $other = imageTestUser('agent');
    $property = imageTestProperty($owner, $this->category->id);

    Sanctum::actingAs($other);

    $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('house.jpg')]
    )->assertForbidden();
});

test('non image files are rejected', function () {
    $agent = imageTestUser('agent');
    $property = imageTestProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->postJson(
        "/api/properties/{$property->id}/images",
        [
            'image' => UploadedFile::fake()->create(
                'document.pdf', 100, 'application/pdf'
            ),
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');
});

test('admin can select a cover image', function () {
    $admin = imageTestUser('super_admin');
    $property = imageTestProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $first = $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('first.jpg')]
    )->json('image.id');

    $second = $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('second.jpg')]
    )->json('image.id');

    $this->patchJson(
        "/api/properties/{$property->id}/images/{$second}/cover"
    )->assertOk();

    expect(PropertyImage::findOrFail($first)->is_cover)->toBeFalse();
    expect(PropertyImage::findOrFail($second)->is_cover)->toBeTrue();
});

test('deleting cover image assigns another cover', function () {
    $admin = imageTestUser('super_admin');
    $property = imageTestProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $first = $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('first.jpg')]
    )->json('image.id');

    $second = $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('second.jpg')]
    )->json('image.id');

    $this->deleteJson(
        "/api/properties/{$property->id}/images/{$first}"
    )->assertOk();

    expect(PropertyImage::findOrFail($second)->is_cover)->toBeTrue();
});

test('public cannot view images of unpublished property', function () {
    $admin = imageTestUser('super_admin');
    $property = imageTestProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $imageId = $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('house.jpg')]
    )->json('image.id');

    auth()->forgetGuards();

    $this->get(
        "/api/public/properties/{$property->id}/images/{$imageId}"
    )->assertNotFound();
});

test('adding an image requires verified property to be reviewed again', function () {
    $admin = imageTestUser('super_admin');
    $property = imageTestProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'verified_at' => now(),
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    Sanctum::actingAs($admin);

    $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('new-photo.jpg')]
    )->assertCreated();

    $property->refresh();

    expect($property->verification_status)->toBe('pending');
    expect($property->is_published)->toBeFalse();
});

test('admin can reorder property images', function () {
    $admin = imageTestUser('super_admin');
    $property = imageTestProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $first = $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('first.jpg')]
    )->json('image.id');

    $second = $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('second.jpg')]
    )->json('image.id');

    $this->patchJson(
        "/api/properties/{$property->id}/images/reorder",
        ['image_ids' => [$second, $first]]
    )
        ->assertOk()
        ->assertJsonPath('images.0.id', $second)
        ->assertJsonPath('images.1.id', $first);
});

test('image reorder rejects missing image ids', function () {
    $admin = imageTestUser('super_admin');
    $property = imageTestProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('first.jpg')]
    )->assertCreated();

    $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('second.jpg')]
    )->assertCreated();

    $first = $property->images()->firstOrFail();

    $this->patchJson(
        "/api/properties/{$property->id}/images/reorder",
        ['image_ids' => [$first->id]]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image_ids');
});

test('another agent cannot reorder property images', function () {
    $owner = imageTestUser('agent');
    $other = imageTestUser('agent');
    $property = imageTestProperty($owner, $this->category->id);

    Sanctum::actingAs($owner);

    $id = $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('house.jpg')]
    )->json('image.id');

    Sanctum::actingAs($other);

    $this->patchJson(
        "/api/properties/{$property->id}/images/reorder",
        ['image_ids' => [$id]]
    )->assertForbidden();
});

test('published property image can be viewed publicly', function () {
    $admin = imageTestUser('super_admin');
    $property = imageTestProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $imageId = $this->postJson(
        "/api/properties/{$property->id}/images",
        ['image' => UploadedFile::fake()->image('house.jpg')]
    )->assertCreated()->json('image.id');

    $property->forceFill([
        'verification_status' => 'verified',
        'verified_at' => now(),
        'availability_status' => 'available',
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    auth()->forgetGuards();

    $this->get(
        "/api/public/properties/{$property->id}/images/{$imageId}"
    )->assertOk();
});

test('image cannot be accessed through another property', function () {
    $admin = imageTestUser('super_admin');

    $first = imageTestProperty($admin, $this->category->id);
    $second = imageTestProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $imageId = $this->postJson(
        "/api/properties/{$first->id}/images",
        ['image' => UploadedFile::fake()->image('house.jpg')]
    )->assertCreated()->json('image.id');

    $this->get(
        "/api/properties/{$second->id}/images/{$imageId}"
    )->assertNotFound();
});

test('public property response excludes private documents', function () {
    $admin = imageTestUser('super_admin');
    $property = imageTestProperty($admin, $this->category->id);

    $property->forceFill([
        'verification_status' => 'verified',
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    $response = $this->getJson(
        "/api/public/properties/{$property->slug}"
    )->assertOk();

    $response->assertJsonMissingPath('property.documents');
    $response->assertJsonMissingPath('property.created_by');
    $response->assertJsonMissingPath('property.agent_id');
});
