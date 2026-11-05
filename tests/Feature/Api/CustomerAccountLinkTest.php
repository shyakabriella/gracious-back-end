<?php

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function linkingUser(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function linkingCustomer(string $reference): Customer
{
    return Customer::create([
        'reference' => $reference,
        'name' => 'Linked Customer',
        'phone' => '0788000001',
    ]);
}

test('linking requires authentication', function () {
    $customer = linkingCustomer('CUS-LINK-01');

    $this->patchJson(
        "/api/customers/{$customer->id}/link-account",
        ['user_id' => linkingUser('customer')->id]
    )->assertUnauthorized();
});

test('admin links an active customer account', function () {
    $admin = linkingUser('admin');
    $customer = linkingCustomer('CUS-LINK-02');
    $account = linkingUser('customer');

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/customers/{$customer->id}/link-account",
        ['user_id' => $account->id]
    )->assertOk()
        ->assertJsonPath('link.user_id', $account->id);

    expect($customer->fresh()->user_id)
        ->toBe($account->id);
});

test('agent cannot link accounts', function () {
    $customer = linkingCustomer('CUS-LINK-03');
    $account = linkingUser('customer');

    Sanctum::actingAs(linkingUser('agent'));

    $this->patchJson(
        "/api/customers/{$customer->id}/link-account",
        ['user_id' => $account->id]
    )->assertForbidden();
});

test('staff and suspended accounts cannot be linked', function () {
    $admin = linkingUser('admin');
    $customer = linkingCustomer('CUS-LINK-04');
    $staff = linkingUser('agent');
    $suspended = linkingUser('customer');
    $suspended->update(['status' => 'suspended']);

    Sanctum::actingAs($admin);

    foreach ([$staff, $suspended] as $account) {
        $this->patchJson(
            "/api/customers/{$customer->id}/link-account",
            ['user_id' => $account->id]
        )->assertUnprocessable();
    }

    expect($customer->fresh()->user_id)->toBeNull();
});

test('one account cannot be linked to two customers', function () {
    $admin = linkingUser('admin');
    $account = linkingUser('customer');
    $first = linkingCustomer('CUS-LINK-05');
    $second = linkingCustomer('CUS-LINK-06');

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/customers/{$first->id}/link-account",
        ['user_id' => $account->id]
    )->assertOk();

    $this->patchJson(
        "/api/customers/{$second->id}/link-account",
        ['user_id' => $account->id]
    )->assertUnprocessable();

    expect($second->fresh()->user_id)->toBeNull();
});

test('already linked customer cannot be reassigned', function () {
    $admin = linkingUser('admin');
    $firstAccount = linkingUser('customer');
    $secondAccount = linkingUser('customer');
    $customer = linkingCustomer('CUS-LINK-07');

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/customers/{$customer->id}/link-account",
        ['user_id' => $firstAccount->id]
    )->assertOk();

    $this->patchJson(
        "/api/customers/{$customer->id}/link-account",
        ['user_id' => $secondAccount->id]
    )->assertUnprocessable();

    expect($customer->fresh()->user_id)
        ->toBe($firstAccount->id);
});

test('customer account cannot link itself', function () {
    $customer = linkingCustomer('CUS-LINK-08');
    $account = linkingUser('customer');

    Sanctum::actingAs($account);

    $this->patchJson(
        "/api/customers/{$customer->id}/link-account",
        ['user_id' => $account->id]
    )->assertForbidden();
});
