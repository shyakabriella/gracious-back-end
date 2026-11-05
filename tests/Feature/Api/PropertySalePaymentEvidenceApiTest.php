<?php

use App\Models\PropertySalePayment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

function evidenceTestPayment(): array
{
    [$admin, $agent, $sale] = paymentTestSale();

    Sanctum::actingAs($admin);

    $record = test()->postJson(
        "/api/sales/{$sale->id}/payments",
        paymentTestPayload('EVIDENCE-' . uniqid())
    )->assertCreated()->json('payment');

    return [$admin, $agent, $sale, PropertySalePayment::findOrFail($record['id'])];
}

test('payment cannot be verified without evidence', function () {
    [$admin, $agent, $sale, $payment] = evidenceTestPayment();

    $this->patchJson(
        "/api/sales/{$sale->id}/payments/{$payment->id}/verify"
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('evidence');

    expect($payment->fresh()->status)->toBe('pending');
});

test('authorized agent can upload private payment receipt', function () {
    [$admin, $agent, $sale, $payment] = evidenceTestPayment();

    Sanctum::actingAs($agent);

    $this->postJson(
        "/api/sales/{$sale->id}/payments/{$payment->id}/evidences",
        ['file' => UploadedFile::fake()->create(
            'receipt.pdf', 50, 'application/pdf'
        )]
    )
        ->assertCreated()
        ->assertJsonMissingPath('evidence.file_path')
        ->assertJsonMissingPath('evidence.disk');

    expect($payment->evidences()->count())->toBe(1);

    $evidence = $payment->evidences()->first();

    Storage::disk('local')->assertExists($evidence->file_path);

    $this->getJson(
        "/api/sales/{$sale->id}/payments/{$payment->id}/evidences"
    )
        ->assertOk()
        ->assertJsonCount(1, 'evidences');

    $this->get(
        "/api/sales/{$sale->id}/payments/{$payment->id}/evidences/{$evidence->id}/download"
    )->assertOk();
});

test('unrelated agent cannot upload or download receipt', function () {
    [$admin, $agent, $sale, $payment] = evidenceTestPayment();

    $other = saleApiUser('agent');

    Sanctum::actingAs($other);

    $this->postJson(
        "/api/sales/{$sale->id}/payments/{$payment->id}/evidences",
        ['file' => UploadedFile::fake()->create(
            'receipt.pdf', 50, 'application/pdf'
        )]
    )->assertNotFound();

    $this->getJson(
        "/api/sales/{$sale->id}/payments/{$payment->id}/evidences"
    )->assertNotFound();
});

test('admin verification creates payment status audit history', function () {
    [$admin, $agent, $sale, $payment] = evidenceTestPayment();

    $this->postJson(
        "/api/sales/{$sale->id}/payments/{$payment->id}/evidences",
        ['file' => UploadedFile::fake()->create(
            'receipt.pdf', 50, 'application/pdf'
        )]
    )->assertCreated();

    $this->patchJson(
        "/api/sales/{$sale->id}/payments/{$payment->id}/verify"
    )
        ->assertOk()
        ->assertJsonPath('payment.status', 'verified');

    $this->assertDatabaseHas('property_sale_payment_status_histories', [
        'property_sale_payment_id' => $payment->id,
        'from_status' => 'pending',
        'to_status' => 'verified',
        'changed_by' => $admin->id,
    ]);
});

test('payment rejection creates audit history', function () {
    [$admin, $agent, $sale, $payment] = evidenceTestPayment();

    $this->patchJson(
        "/api/sales/{$sale->id}/payments/{$payment->id}/reject",
        ['reason' => 'Bank receipt not confirmed.']
    )->assertOk();

    $this->assertDatabaseHas('property_sale_payment_status_histories', [
        'property_sale_payment_id' => $payment->id,
        'from_status' => 'pending',
        'to_status' => 'rejected',
        'changed_by' => $admin->id,
    ]);
});
