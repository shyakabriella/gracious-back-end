<?php

use App\Models\PropertySale;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function saleStatusRecord($admin, $agent): PropertySale
{
    $property = saleApiProperty($admin, $agent);
    $buyer = saleApiCustomer($admin);

    return PropertySale::create([
        'reference' => 'SALE-' . uniqid(),
        'property_id' => $property->id,
        'customer_id' => $buyer->id,
        'agent_id' => $agent->id,
        'asking_price' => $property->asking_price,
        'agreed_price' => 82000000,
        'currency' => 'RWF',
        'created_by' => $admin->id,
    ]);
}

test('admin can approve a sale agreement', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $sale = saleStatusRecord($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/sales/{$sale->id}/agreement", [
        'agreement_date' => now()->toDateString(),
    ])
        ->assertOk()
        ->assertJsonPath('sale.status', 'agreement');

    expect($sale->fresh()->agreement_date)->not->toBeNull();

    $this->assertDatabaseHas('property_sale_status_histories', [
        'property_sale_id' => $sale->id,
        'from_status' => 'negotiation',
        'to_status' => 'agreement',
        'changed_by' => $admin->id,
    ]);
});

test('sale cannot skip directly to pending completion', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $sale = saleStatusRecord($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/sales/{$sale->id}/pending-completion")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('admin can move agreed sale to pending completion', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $sale = saleStatusRecord($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/sales/{$sale->id}/agreement", [
        'agreement_date' => now()->toDateString(),
    ])->assertOk();

    $this->patchJson("/api/sales/{$sale->id}/pending-completion")
        ->assertOk()
        ->assertJsonPath('sale.status', 'pending_completion');
});

test('cancelling negotiation requires reason and preserves property availability', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $sale = saleStatusRecord($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/sales/{$sale->id}/cancel", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    $this->patchJson("/api/sales/{$sale->id}/cancel", [
        'reason' => 'Buyer decided not to proceed.',
    ])
        ->assertOk()
        ->assertJsonPath('sale.status', 'cancelled');

    expect($sale->fresh()->property->availability_status)
        ->toBe('available');

    $this->patchJson("/api/sales/{$sale->id}/agreement", [
        'agreement_date' => now()->toDateString(),
    ])->assertUnprocessable();
});

test('assigned agent can cancel negotiation but cannot approve agreement', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $sale = saleStatusRecord($admin, $agent);

    Sanctum::actingAs($agent);

    $this->patchJson("/api/sales/{$sale->id}/agreement", [
        'agreement_date' => now()->toDateString(),
    ])->assertForbidden();

    $this->patchJson("/api/sales/{$sale->id}/cancel", [
        'reason' => 'Buyer withdrew.',
    ])->assertOk();
});

test('unrelated agent cannot cancel or view sale history', function () {
    $admin = saleApiUser('super_admin');
    $assigned = saleApiUser('agent');
    $other = saleApiUser('agent');
    $sale = saleStatusRecord($admin, $assigned);

    Sanctum::actingAs($other);

    $this->patchJson("/api/sales/{$sale->id}/cancel", [
        'reason' => 'Not my transaction.',
    ])->assertNotFound();

    $this->getJson("/api/sales/{$sale->id}/history")
        ->assertNotFound();
});

test('admin can review sale status history', function () {
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $sale = saleStatusRecord($admin, $agent);

    Sanctum::actingAs($admin);

    $this->patchJson("/api/sales/{$sale->id}/agreement", [
        'agreement_date' => now()->toDateString(),
    ])->assertOk();

    $this->getJson("/api/sales/{$sale->id}/history")
        ->assertOk()
        ->assertJsonCount(1, 'history')
        ->assertJsonPath('history.0.to_status', 'agreement');
});
