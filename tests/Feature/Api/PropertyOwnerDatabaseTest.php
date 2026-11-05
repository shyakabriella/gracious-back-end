<?php

use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('property owner tables exist', function () {
    expect(Schema::hasTable('property_owners'))->toBeTrue();
    expect(Schema::hasTable('property_owner'))->toBeTrue();
});

test('one owner can be linked to multiple properties', function () {
    $user = User::factory()->create(['status' => 'active']);

    $category = PropertyCategory::create([
        'name' => 'House',
        'slug' => 'house',
    ]);

    $owner = PropertyOwner::create([
        'reference' => 'OWN-000001',
        'owner_type' => 'individual',
        'name' => 'Test Owner',
        'phone' => '0780000000',
        'created_by' => $user->id,
    ]);

    foreach ([1, 2] as $number) {
        $property = Property::create([
            'reference' => 'GI-' . $number,
            'title' => 'Property ' . $number,
            'slug' => 'property-' . $number,
            'category_id' => $category->id,
            'listing_type' => 'sale',
            'asking_price' => 10000000,
            'created_by' => $user->id,
        ]);

        $owner->properties()->attach($property->id);
    }

    expect($owner->properties()->count())->toBe(2);
    expect(Property::firstOrFail()->owners()->count())->toBe(1);
});

test('property owners support soft deletion', function () {
    $user = User::factory()->create(['status' => 'active']);

    $owner = PropertyOwner::create([
        'reference' => 'OWN-000002',
        'name' => 'Another Owner',
        'created_by' => $user->id,
    ]);

    $owner->delete();

    expect(PropertyOwner::count())->toBe(0);
    expect(PropertyOwner::withTrashed()->count())->toBe(1);
});
