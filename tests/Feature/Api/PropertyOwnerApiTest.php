<?php

use App\Models\PropertyOwner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function ownerApiUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function ownerApiPayload(): array
{
    return [
        'owner_type' => 'individual',
        'name' => 'John Property Owner',
        'phone' => '0780000000',
        'email' => 'owner@example.com',
        'district' => 'Gasabo',
        'sector' => 'Kimironko',
    ];
}

test('owner routes require authentication', function () {
    $this->getJson('/api/owners')->assertUnauthorized();
    $this->postJson('/api/owners', [])->assertUnauthorized();
});

test('admin can register a property owner', function () {
    $admin = ownerApiUser('super_admin');

    Sanctum::actingAs($admin);

    $this->postJson('/api/owners', ownerApiPayload())
        ->assertCreated()
        ->assertJsonPath('owner.reference', 'OWN-000001')
        ->assertJsonPath('owner.name', 'John Property Owner');

    $this->assertDatabaseHas('property_owners', [
        'name' => 'John Property Owner',
        'created_by' => $admin->id,
    ]);
});

test('agent can register own property owner', function () {
    $agent = ownerApiUser('agent');

    Sanctum::actingAs($agent);

    $this->postJson('/api/owners', ownerApiPayload())
        ->assertCreated()
        ->assertJsonPath('owner.created_by', $agent->id);
});

test('customer cannot register property owner', function () {
    Sanctum::actingAs(ownerApiUser('customer'));

    $this->postJson('/api/owners', ownerApiPayload())
        ->assertForbidden();
});

test('invalid owner email is rejected', function () {
    Sanctum::actingAs(ownerApiUser('super_admin'));

    $this->postJson('/api/owners', [
        ...ownerApiPayload(),
        'email' => 'not-an-email',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('agent cannot list another agents owners', function () {
    $first = ownerApiUser('agent');
    $second = ownerApiUser('agent');

    Sanctum::actingAs($first);

    $ownerId = $this->postJson('/api/owners', ownerApiPayload())
        ->assertCreated()
        ->json('owner.id');

    Sanctum::actingAs($second);

    $this->getJson('/api/owners')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson("/api/owners/{$ownerId}")
        ->assertNotFound();
});

test('agent search cannot expose another agents owners', function () {
    $first = ownerApiUser('agent');
    $second = ownerApiUser('agent');

    Sanctum::actingAs($first);

    $this->postJson('/api/owners', ownerApiPayload())
        ->assertCreated();

    Sanctum::actingAs($second);

    $this->getJson('/api/owners?search=John')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('admin can update an owner', function () {
    Sanctum::actingAs(ownerApiUser('super_admin'));

    $id = $this->postJson('/api/owners', ownerApiPayload())
        ->assertCreated()
        ->json('owner.id');

    $this->putJson("/api/owners/{$id}", [
        'name' => 'Updated Property Owner',
        'phone' => '0781111111',
    ])
        ->assertOk()
        ->assertJsonPath('owner.name', 'Updated Property Owner');
});

test('another agent cannot edit owner', function () {
    $first = ownerApiUser('agent');
    $second = ownerApiUser('agent');

    Sanctum::actingAs($first);

    $id = $this->postJson('/api/owners', ownerApiPayload())
        ->assertCreated()
        ->json('owner.id');

    Sanctum::actingAs($second);

    $this->putJson("/api/owners/{$id}", [
        'name' => 'Unauthorized Change',
    ])->assertForbidden();
});

test('admin can archive an unlinked property owner', function () {
    Sanctum::actingAs(ownerApiUser('super_admin'));

    $id = $this->postJson('/api/owners', ownerApiPayload())
        ->assertCreated()
        ->json('owner.id');

    $this->deleteJson("/api/owners/{$id}")
        ->assertOk();

    expect(PropertyOwner::count())->toBe(0);
    expect(PropertyOwner::withTrashed()->count())->toBe(1);
});

test('super admin has owner deletion permission', function () {
    $admin = ownerApiUser('super_admin');

    expect($admin->can('owners.delete'))->toBeTrue();
    expect($admin->hasRole('super_admin'))->toBeTrue();

    Sanctum::actingAs($admin);

    $this->getJson('/api/owners')->assertOk();
});
