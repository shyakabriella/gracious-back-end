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

function dashboardProperty(User $user, int $categoryId): Property
{
    return Property::create([
        'reference' => 'GI-' . uniqid(),
        'title' => 'House in Kigali',
        'slug' => 'house-' . uniqid(),
        'category_id' => $categoryId,
        'listing_type' => 'sale',
        'asking_price' => 100000000,
        'created_by' => $user->id,
    ]);
}

test('dashboard returns zero property statistics when empty', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('super_admin');

    Sanctum::actingAs($admin);

    $this->getJson('/api/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('statistics.properties.available', true)
        ->assertJsonPath('statistics.properties.total', 0)
        ->assertJsonPath('statistics.properties.published', 0);
});

test('dashboard counts real property records', function () {
    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('super_admin');

    $draft = dashboardProperty($admin, $this->category->id);
    $published = dashboardProperty($admin, $this->category->id);

    $published->forceFill([
        'verification_status' => 'verified',
        'verified_at' => now(),
        'is_published' => true,
        'published_at' => now(),
    ])->save();

    Sanctum::actingAs($admin);

    $this->getJson('/api/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('statistics.properties.total', 2)
        ->assertJsonPath('statistics.properties.draft', 1)
        ->assertJsonPath('statistics.properties.verified', 1)
        ->assertJsonPath('statistics.properties.published', 1)
        ->assertJsonPath('statistics.properties.for_sale', 2);

    $draft->delete();

    $this->getJson('/api/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('statistics.properties.total', 1);
});

test('agent dashboard only counts assigned or created properties', function () {
    $first = User::factory()->create(['status' => 'active']);
    $first->assignRole('agent');

    $second = User::factory()->create(['status' => 'active']);
    $second->assignRole('agent');

    dashboardProperty($first, $this->category->id);
    dashboardProperty($second, $this->category->id);

    Sanctum::actingAs($first);

    $this->getJson('/api/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('statistics.properties.total', 1);
});
