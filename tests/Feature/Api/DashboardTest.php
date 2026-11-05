<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function dashboardUser(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

test('dashboard requires authentication', function () {
    $this->getJson('/api/dashboard/summary')
        ->assertUnauthorized();
});

test('super admin can view dashboard', function () {
    $admin = dashboardUser('super_admin');

    Sanctum::actingAs($admin);

    $this->getJson('/api/dashboard/summary')
        ->assertOk()
        ->assertJsonPath(
            'message',
            'Dashboard loaded successfully'
        )
        ->assertJsonPath('user.id', $admin->id);
});

test('dashboard returns real staff statistics', function () {
    $admin = dashboardUser('super_admin');

    dashboardUser('agent');
    dashboardUser('accountant');

    $suspended = dashboardUser('agent');
    $suspended->update(['status' => 'suspended']);

    Sanctum::actingAs($admin);

    $this->getJson('/api/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('statistics.staff.available', true)
        ->assertJsonPath('statistics.staff.total', 4)
        ->assertJsonPath('statistics.staff.active', 3)
        ->assertJsonPath('statistics.staff.suspended', 1)
        ->assertJsonPath('statistics.staff.by_role.agent', 2)
        ->assertJsonPath('statistics.staff.by_role.accountant', 1);
});

test('agent cannot see staff statistics', function () {
    $agent = dashboardUser('agent');

    Sanctum::actingAs($agent);

    $response = $this->getJson('/api/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('statistics.staff.available', false);

    expect(
        $response->json('statistics.staff.total')
    )->toBeNull();
});

test('customer cannot access internal dashboard', function () {
    $customer = dashboardUser('customer');

    Sanctum::actingAs($customer);

    $this->getJson('/api/dashboard/summary')
        ->assertForbidden();
});

test('suspended staff cannot access dashboard', function () {
    $agent = dashboardUser('agent');

    $agent->update(['status' => 'suspended']);

    $token = $agent->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/dashboard/summary')
        ->assertForbidden();
});

test('unimplemented business statistics are not fabricated', function () {
    $admin = dashboardUser('super_admin');

    Sanctum::actingAs($admin);

    $this->getJson('/api/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('statistics.properties.available', true)
        ->assertJsonPath('statistics.sales.available', false)
        ->assertJsonPath('statistics.rentals.available', false)
        ->assertJsonPath('statistics.leads.available', false)
        ->assertJsonPath('statistics.finance.available', false);
});

test('agent cannot access finance statistics', function () {
    $agent = dashboardUser('agent');

    Sanctum::actingAs($agent);

    $this->getJson('/api/dashboard/summary')
        ->assertOk()
        ->assertJsonPath('statistics.finance.available', false)
        ->assertJsonPath(
            'statistics.finance.reason',
            'Access restricted'
        );
});
