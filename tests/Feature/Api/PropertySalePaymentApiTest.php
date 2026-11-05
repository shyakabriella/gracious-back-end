<?php

use App\Models\PropertySale;
use App\Models\PropertySalePayment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function paymentTestSale(): array
{
    $admin = saleApiUser('super_admin');
    $agent = saleApiUser('agent');
    $sale = saleStatusRecord($admin, $agent);

    $sale->update([
        'status' => 'agreement',
        'agreement_date' => now()->toDateString(),
    ]);

    return [$admin, $agent, $sale];
}

function paymentTestPayload(string $reference = 'BANK-TEST-001'): array
{
    return [
        'payment_type' => 'deposit',
        'payment_method' => 'bank_transfer',
        'amount' => 10000000,
        'transaction_reference' => $reference,
        'payment_date' => now()->toDateString(),
    ];
}

test('payment endpoints require authentication', function () {
    $this->getJson('/api/sales/1/payments')->assertUnauthorized();
    $this->postJson('/api/sales/1/payments', [])
        ->assertUnauthorized();
});

test('assigned agent can record pending payment', function () {
    [$admin, $agent, $sale] = paymentTestSale();

    Sanctum::actingAs($agent);

    $this->postJson(
        "/api/sales/{$sale->id}/payments",
        paymentTestPayload()
    )
        ->assertCreated()
        ->assertJsonPath('payment.status', 'pending')
        ->assertJsonPath('payment.currency', 'RWF');

    expect(PropertySalePayment::count())->toBe(1);
    expect(PropertySalePayment::first()->verified_at)->toBeNull();
});

test('payment is excluded from balance until verified', function () {
    [$admin, $agent, $sale] = paymentTestSale();

    Sanctum::actingAs($agent);

    $payment = $this->postJson(
        "/api/sales/{$sale->id}/payments",
        paymentTestPayload()
    )->assertCreated()->json('payment');

    $this->getJson("/api/sales/{$sale->id}/payments")
        ->assertOk()
        ->assertJsonPath('summary.verified_amount', '0.00')
        ->assertJsonPath('summary.balance', '82000000.00');

    Sanctum::actingAs($admin);

    \Illuminate\Support\Facades\Storage::fake('local');

    $this->postJson(
        "/api/sales/{$sale->id}/payments/{$payment['id']}/evidences",
        [
            'file' => \Illuminate\Http\UploadedFile::fake()
                ->create('bank-receipt.pdf', 50, 'application/pdf'),
        ]
    )->assertCreated();

    $this->patchJson(
        "/api/sales/{$sale->id}/payments/{$payment['id']}/verify"
    )
        ->assertOk()
        ->assertJsonPath('payment.status', 'verified');

    $this->getJson("/api/sales/{$sale->id}/payments")
        ->assertOk()
        ->assertJsonPath('summary.verified_amount', '10000000.00')
        ->assertJsonPath('summary.balance', '72000000.00');
});

test('agent cannot verify payment', function () {
    [$admin, $agent, $sale] = paymentTestSale();

    Sanctum::actingAs($admin);

    $payment = $this->postJson(
        "/api/sales/{$sale->id}/payments",
        paymentTestPayload()
    )->assertCreated()->json('payment');

    Sanctum::actingAs($agent);

    $this->patchJson(
        "/api/sales/{$sale->id}/payments/{$payment['id']}/verify"
    )->assertForbidden();

    expect(PropertySalePayment::first()->status)->toBe('pending');
});

test('duplicate external transaction reference is rejected', function () {
    [$admin, $agent, $sale] = paymentTestSale();

    Sanctum::actingAs($admin);

    $this->postJson(
        "/api/sales/{$sale->id}/payments",
        paymentTestPayload()
    )->assertCreated();

    $this->postJson(
        "/api/sales/{$sale->id}/payments",
        paymentTestPayload()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('transaction_reference');

    expect(PropertySalePayment::count())->toBe(1);
});

test('payment cannot exceed sale price', function () {
    [$admin, $agent, $sale] = paymentTestSale();

    Sanctum::actingAs($admin);

    $payload = paymentTestPayload();
    $payload['amount'] = 90000000;

    $this->postJson(
        "/api/sales/{$sale->id}/payments",
        $payload
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

test('rejected payment does not increase verified total', function () {
    [$admin, $agent, $sale] = paymentTestSale();

    Sanctum::actingAs($admin);

    $payment = $this->postJson(
        "/api/sales/{$sale->id}/payments",
        paymentTestPayload()
    )->assertCreated()->json('payment');

    $this->patchJson(
        "/api/sales/{$sale->id}/payments/{$payment['id']}/reject",
        ['reason' => 'Bank reference could not be confirmed.']
    )
        ->assertOk()
        ->assertJsonPath('payment.status', 'rejected');

    $this->getJson("/api/sales/{$sale->id}/payments")
        ->assertJsonPath('summary.verified_amount', '0.00');

    $this->patchJson(
        "/api/sales/{$sale->id}/payments/{$payment['id']}/verify"
    )
        ->assertUnprocessable();
});

test('negotiation cannot record payments before agreement', function () {
    [$admin, $agent, $sale] = paymentTestSale();

    $sale->update(['status' => 'negotiation']);

    Sanctum::actingAs($admin);

    $this->postJson(
        "/api/sales/{$sale->id}/payments",
        paymentTestPayload()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sale');
});

test('unrelated agent cannot access sale payments', function () {
    [$admin, $agent, $sale] = paymentTestSale();
    $other = saleApiUser('agent');

    Sanctum::actingAs($other);

    $this->getJson("/api/sales/{$sale->id}/payments")
        ->assertNotFound();

    $this->postJson(
        "/api/sales/{$sale->id}/payments",
        paymentTestPayload()
    )->assertNotFound();
});
