<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('property tables exist', function () {
    expect(Schema::hasTable('properties'))->toBeTrue();
    expect(Schema::hasTable('property_categories'))->toBeTrue();
});

test('property belongs to category and creator', function () {
    $user = User::factory()->create();

    $category = PropertyCategory::create([
        'name' => 'House',
        'slug' => 'house',
    ]);

    $property = Property::create([
        'reference' => 'GI-000001',
        'title' => 'House in Kigali',
        'slug' => 'house-in-kigali',
        'category_id' => $category->id,
        'listing_type' => 'sale',
        'asking_price' => 150000000,
        'created_by' => $user->id,
    ]);

    expect($property->category->id)->toBe($category->id);
    expect($property->creator->id)->toBe($user->id);
    expect($property->verification_status)->toBe('draft');
    expect($property->availability_status)->toBe('available');
    expect($property->is_published)->toBeFalse();
});

test('deleting a property uses soft deletes', function () {
    $user = User::factory()->create();

    $category = PropertyCategory::create([
        'name' => 'Land',
        'slug' => 'land',
    ]);

    $property = Property::create([
        'reference' => 'GI-000002',
        'title' => 'Land in Kigali',
        'slug' => 'land-in-kigali',
        'category_id' => $category->id,
        'listing_type' => 'sale',
        'asking_price' => 30000000,
        'created_by' => $user->id,
    ]);

    $property->delete();

    expect(Property::count())->toBe(0);
    expect(Property::withTrashed()->count())->toBe(1);
});
