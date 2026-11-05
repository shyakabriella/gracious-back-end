<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyDocument;
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

function documentUser(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function documentProperty(User $user, int $categoryId): Property
{
    return Property::create([
        'reference' => 'GI-' . uniqid(),
        'title' => 'House in Kigali',
        'slug' => 'house-' . uniqid(),
        'category_id' => $categoryId,
        'listing_type' => 'sale',
        'asking_price' => 90000000,
        'created_by' => $user->id,
    ]);
}

function uploadDocument(int $propertyId)
{
    return test()->postJson(
        "/api/properties/{$propertyId}/documents",
        [
            'title' => 'Ownership Certificate',
            'document_type' => 'ownership',
            'file' => UploadedFile::fake()->create(
                'ownership.pdf',
                100,
                'application/pdf'
            ),
        ]
    );
}

test('agent can upload document to own property', function () {
    $agent = documentUser('agent');
    $property = documentProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    uploadDocument($property->id)
        ->assertCreated()
        ->assertJsonPath('document.document_type', 'ownership');

    expect(PropertyDocument::count())->toBe(1);

    Storage::disk('local')->assertExists(
        PropertyDocument::firstOrFail()->file_path
    );
});

test('another agent cannot upload a document', function () {
    $creator = documentUser('agent');
    $other = documentUser('agent');

    $property = documentProperty($creator, $this->category->id);

    Sanctum::actingAs($other);

    uploadDocument($property->id)->assertForbidden();

    expect(PropertyDocument::count())->toBe(0);
});

test('unauthenticated visitor cannot list documents', function () {
    $agent = documentUser('agent');
    $property = documentProperty($agent, $this->category->id);

    $this->getJson(
        "/api/properties/{$property->id}/documents"
    )->assertUnauthorized();
});

test('document paths are not exposed in API response', function () {
    $agent = documentUser('agent');
    $property = documentProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    uploadDocument($property->id)
        ->assertCreated()
        ->assertJsonMissingPath('document.file_path');

    $this->getJson(
        "/api/properties/{$property->id}/documents"
    )
        ->assertOk()
        ->assertJsonCount(1, 'documents')
        ->assertJsonMissingPath('documents.0.file_path');
});

test('owner agent can download document', function () {
    $agent = documentUser('agent');
    $property = documentProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $documentId = uploadDocument($property->id)
        ->assertCreated()
        ->json('document.id');

    $this->get(
        "/api/properties/{$property->id}/documents/{$documentId}/download"
    )->assertOk();
});

test('another agent cannot download document', function () {
    $creator = documentUser('agent');
    $other = documentUser('agent');
    $property = documentProperty($creator, $this->category->id);

    Sanctum::actingAs($creator);

    $documentId = uploadDocument($property->id)
        ->assertCreated()
        ->json('document.id');

    Sanctum::actingAs($other);

    $this->getJson(
        "/api/properties/{$property->id}/documents/{$documentId}/download"
    )->assertForbidden();
});

test('unsupported document type is rejected', function () {
    $agent = documentUser('agent');
    $property = documentProperty($agent, $this->category->id);

    Sanctum::actingAs($agent);

    $this->postJson(
        "/api/properties/{$property->id}/documents",
        [
            'title' => 'Invalid File',
            'document_type' => 'ownership',
            'file' => UploadedFile::fake()->create(
                'script.exe', 100, 'application/octet-stream'
            ),
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

test('document from another property cannot be downloaded', function () {
    $admin = documentUser('super_admin');

    $first = documentProperty($admin, $this->category->id);
    $second = documentProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $documentId = uploadDocument($first->id)
        ->assertCreated()
        ->json('document.id');

    $this->getJson(
        "/api/properties/{$second->id}/documents/{$documentId}/download"
    )->assertNotFound();
});

test('authorized user can delete document and stored file', function () {
    $admin = documentUser('super_admin');
    $property = documentProperty($admin, $this->category->id);

    Sanctum::actingAs($admin);

    $documentId = uploadDocument($property->id)
        ->assertCreated()
        ->json('document.id');

    $path = PropertyDocument::findOrFail($documentId)->file_path;

    $this->deleteJson(
        "/api/properties/{$property->id}/documents/{$documentId}"
    )->assertOk();

    expect(PropertyDocument::count())->toBe(0);

    Storage::disk('local')->assertMissing($path);
});
