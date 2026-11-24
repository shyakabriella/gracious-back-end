<?php

use App\Models\PropertyCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('visitors can list only active public property categories', function () {
    if (
        config('database.default') !== 'sqlite'
        || config('database.connections.sqlite.database') !== ':memory:'
    ) {
        throw new RuntimeException('Unsafe database selected.');
    }

    $active = PropertyCategory::create([
        'name' => 'Houses',
        'slug' => 'houses-public-202',
        'is_active' => true,
    ]);

    PropertyCategory::create([
        'name' => 'Hidden Category',
        'slug' => 'hidden-public-202',
        'is_active' => false,
    ]);

    $this->getJson('/api/public/property-categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id)
        ->assertJsonPath('data.0.name', 'Houses')
        ->assertJsonMissing(['name' => 'Hidden Category'])
        ->assertJsonMissingPath('data.0.is_active')
        ->assertJsonMissingPath('data.0.slug');
});
