<?php

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function portalUser(string $role = 'customer'): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function portalProfile(User $user, string $reference): Customer
{
    $profile = Customer::create([
        'reference' => $reference,
        'name' => 'Customer ' . $reference,
        'phone' => '0788000001',
    ]);

    $profile->forceFill([
        'user_id' => $user->id,
    ])->save();

    return $profile;
}

function portalLead(Customer $customer, string $reference): Lead
{
    return Lead::create([
        'reference' => $reference,
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
        'source' => 'website',
        'subject' => 'Property inquiry',
    ]);
}

test('customer dashboard requires authentication', function () {
    $this->getJson('/api/customer/dashboard')
        ->assertUnauthorized();
});

test('customer with no linked profile sees empty dashboard', function () {
    Sanctum::actingAs(portalUser());

    $this->getJson('/api/customer/dashboard')
        ->assertOk()
        ->assertJsonPath('profile', null)
        ->assertJsonPath('inquiries.total', 0)
        ->assertJsonCount(0, 'inquiries.recent');
});

test('customer sees own inquiries and not another customers', function () {
    $first = portalUser();
    $second = portalUser();

    $mine = portalProfile($first, 'CUS-PORTAL-A');
    $other = portalProfile($second, 'CUS-PORTAL-B');

    portalLead($mine, 'LEAD-PORTAL-A');
    portalLead($other, 'LEAD-PORTAL-B');

    Sanctum::actingAs($first);

    $this->getJson('/api/customer/dashboard')
        ->assertOk()
        ->assertJsonPath('profile.reference', 'CUS-PORTAL-A')
        ->assertJsonPath('inquiries.total', 1)
        ->assertJsonCount(1, 'inquiries.recent')
        ->assertJsonPath(
            'inquiries.recent.0.reference',
            'LEAD-PORTAL-A'
        )
        ->assertDontSee('LEAD-PORTAL-B')
        ->assertDontSee('CUS-PORTAL-B');
});

test('staff cannot access customer dashboard', function () {
    Sanctum::actingAs(portalUser('agent'));

    $this->getJson('/api/customer/dashboard')
        ->assertForbidden();
});

test('suspended customer cannot access dashboard', function () {
    $user = portalUser();
    $user->update(['status' => 'suspended']);

    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/customer/dashboard')
        ->assertForbidden();
});

test('internal dashboard remains forbidden to customers', function () {
    Sanctum::actingAs(portalUser());

    $this->getJson('/api/dashboard/summary')
        ->assertForbidden();
});

test('internal lead notes are not exposed to customers', function () {
    $user = portalUser();
    $profile = portalProfile($user, 'CUS-PORTAL-PRIVATE');

    $lead = portalLead($profile, 'LEAD-PORTAL-PRIVATE');

    $lead->forceFill([
        'internal_notes' => 'STAFF_CONFIDENTIAL_NOTE',
    ])->save();

    Sanctum::actingAs($user);

    $this->getJson('/api/customer/dashboard')
        ->assertOk()
        ->assertDontSee('STAFF_CONFIDENTIAL_NOTE');
});
