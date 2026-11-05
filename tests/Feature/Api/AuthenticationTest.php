<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function createStaff(string $role = 'agent'): User
{
    $user = User::factory()->create([
        'status' => 'active',
        'password' => Hash::make('TestPassword123!'),
    ]);

    $user->assignRole($role);

    return $user;
}

test('user can login with correct credentials', function () {
    $user = createStaff();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'TestPassword123!',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Login successful')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure(['token', 'user']);

    expect($user->tokens()->count())->toBe(1);
});

test('incorrect password is rejected', function () {
    $user = createStaff();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'WrongPassword123!',
    ])->assertUnprocessable();

    expect($user->tokens()->count())->toBe(0);
});

test('unauthenticated users cannot access protected endpoints', function () {
    $this->getJson('/api/me')->assertUnauthorized();
    $this->getJson('/api/users')->assertUnauthorized();
    $this->getJson('/api/roles')->assertUnauthorized();
});

test('authenticated user can view own profile', function () {
    $user = createStaff();

    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.status', 'active');
});

test('suspended users cannot login', function () {
    $user = createStaff();

    $user->update(['status' => 'suspended']);

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'TestPassword123!',
    ])->assertForbidden();

    expect($user->tokens()->count())->toBe(0);
});

test('suspended user cannot use an existing token', function () {
    $user = createStaff();

    $token = $user->createToken('test')->plainTextToken;

    $user->update(['status' => 'suspended']);

    $this->withToken($token)
        ->getJson('/api/me')
        ->assertForbidden();
});

test('super admin can create staff', function () {
    $admin = createStaff('super_admin');

    Sanctum::actingAs($admin);

    $this->postJson('/api/users', [
        'name' => 'Test Agent',
        'email' => 'agent@example.test',
        'role' => 'agent',
        'password' => 'SecureAgent123!',
        'password_confirmation' => 'SecureAgent123!',
    ])
        ->assertCreated()
        ->assertJsonPath('user.email', 'agent@example.test');

    $user = User::where('email', 'agent@example.test')->firstOrFail();

    expect($user->hasRole('agent'))->toBeTrue();
    expect($user->status)->toBe('active');
});

test('agent cannot create staff', function () {
    $agent = createStaff('agent');

    Sanctum::actingAs($agent);

    $this->postJson('/api/users', [
        'name' => 'New Staff',
        'email' => 'staff@example.test',
        'role' => 'agent',
        'password' => 'SecureAgent123!',
        'password_confirmation' => 'SecureAgent123!',
    ])->assertForbidden();

    $this->assertDatabaseMissing('users', [
        'email' => 'staff@example.test',
    ]);
});

test('super admin can search and filter users', function () {
    $admin = createStaff('super_admin');

    User::factory()->create([
        'name' => 'Alice Property',
        'email' => 'alice@example.test',
        'status' => 'active',
    ])->assignRole('agent');

    Sanctum::actingAs($admin);

    $this->getJson('/api/users?search=Alice&role=agent&status=active')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('super admin can suspend an agent', function () {
    $admin = createStaff('super_admin');
    $agent = createStaff('agent');

    Sanctum::actingAs($admin);

    $this->patchJson("/api/users/{$agent->id}/status", [
        'status' => 'suspended',
    ])
        ->assertOk()
        ->assertJsonPath('user.status', 'suspended');

    expect($agent->fresh()->status)->toBe('suspended');
});

test('admin cannot suspend own account', function () {
    $admin = createStaff('super_admin');

    Sanctum::actingAs($admin);

    $this->patchJson("/api/users/{$admin->id}/status", [
        'status' => 'suspended',
    ])->assertForbidden();

    expect($admin->fresh()->status)->toBe('active');
});

test('agent cannot manage roles', function () {
    $agent = createStaff('agent');

    Sanctum::actingAs($agent);

    $this->getJson('/api/roles')->assertForbidden();
});

test('user can change password', function () {
    $user = createStaff('agent');

    Sanctum::actingAs($user);

    $this->putJson('/api/change-password', [
        'current_password' => 'TestPassword123!',
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ])->assertOk();

    expect(Hash::check(
        'NewSecurePassword123!',
        $user->fresh()->password
    ))->toBeTrue();
});

test('incorrect current password is rejected', function () {
    $user = createStaff('agent');

    Sanctum::actingAs($user);

    $this->putJson('/api/change-password', [
        'current_password' => 'IncorrectPassword!',
        'password' => 'NewSecurePassword123!',
        'password_confirmation' => 'NewSecurePassword123!',
    ])->assertUnprocessable();
});

test('logout revokes the current access token', function () {
    $user = createStaff('agent');
    $token = $user->createToken('test-token')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/logout')
        ->assertOk();

    expect($user->tokens()->count())->toBe(0);

    // Clear the authenticated guard between requests.
    auth()->forgetGuards();

    $this->withToken($token)
        ->getJson('/api/me')
        ->assertUnauthorized();
});

test('super admin can update staff information', function () {
    $admin = createStaff('super_admin');
    $agent = createStaff('agent');

    Sanctum::actingAs($admin);

    $this->putJson("/api/users/{$agent->id}", [
        'name' => 'Updated Agent',
        'email' => 'updated@example.test',
    ])
        ->assertOk()
        ->assertJsonPath('user.name', 'Updated Agent');

    expect($agent->fresh()->email)->toBe('updated@example.test');
});

test('super admin can reactivate suspended staff', function () {
    $admin = createStaff('super_admin');
    $agent = createStaff('agent');

    $agent->update(['status' => 'suspended']);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/users/{$agent->id}/status", [
        'status' => 'active',
    ])
        ->assertOk()
        ->assertJsonPath('user.status', 'active');

    expect($agent->fresh()->isActive())->toBeTrue();
});

test('duplicate staff email is rejected', function () {
    $admin = createStaff('super_admin');
    $agent = createStaff('agent');

    Sanctum::actingAs($admin);

    $this->postJson('/api/users', [
        'name' => 'Duplicate Agent',
        'email' => $agent->email,
        'role' => 'agent',
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('last active super admin cannot lose their role', function () {
    $admin = createStaff('super_admin');

    $otherAdmin = createStaff('super_admin');
    $otherAdmin->update(['status' => 'suspended']);

    Sanctum::actingAs($admin);

    $this->putJson("/api/users/{$otherAdmin->id}", [
        'role' => 'agent',
    ])->assertOk();

    expect($otherAdmin->fresh()->hasRole('agent'))->toBeTrue();

    $this->putJson("/api/users/{$admin->id}", [
        'role' => 'agent',
    ])->assertForbidden();

    expect($admin->fresh()->hasRole('super_admin'))->toBeTrue();
});
