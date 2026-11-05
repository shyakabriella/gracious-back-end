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

function completionConfirmation(): array
{
    return [
        'confirm_documents_reviewed' => true,
        'confirm_transfer_registered' => true,
        'notes' => 'Transfer documents and payment reconciled.',
    ];
}

function completionTestSale(): array
{
    [$admin, $agent, $sale] = paymentTestSale();

    $sale->update([
        'status' => 'pending_completion',
    ]);

    return [$admin, $agent, $sale];
}

function completionAddDocuments($sale): void
{
    foreach ([
        'signed_agreement',
        'ownership_transfer',
    ] as $type) {
        test()->postJson(
            "/api/sales/{$sale->id}/completion-documents",
            [
                'document_type' => $type,
                'file' => UploadedFile::fake()->create(
                    "{$type}.pdf",
                    50,
                    'application/pdf'
                ),
            ]
        )->assertCreated();
    }
}

function completionAddFullPayment($sale): void
{
    $payload = paymentTestPayload(
        'FINAL-COMPLETION-' . uniqid()
    );

    $payload['amount'] = 82000000;

    $payment = test()->postJson(
        "/api/sales/{$sale->id}/payments",
        $payload
    )->assertCreated()->json('payment');

    test()->postJson(
        "/api/sales/{$sale->id}/payments/{$payment['id']}/evidences",
        [
            'file' => UploadedFile::fake()->create(
                'payment-receipt.pdf',
                50,
                'application/pdf'
            ),
        ]
    )->assertCreated();

    test()->patchJson(
        "/api/sales/{$sale->id}/payments/{$payment['id']}/verify"
    )
        ->assertOk()
        ->assertJsonPath('payment.status', 'verified');
}

test('unauthenticated users cannot complete sale', function () {
    $this->patchJson(
        '/api/sales/1/complete',
        completionConfirmation()
    )->assertUnauthorized();
});

test('assigned agent cannot complete property sale', function () {
    [$admin, $agent, $sale] = completionTestSale();

    Sanctum::actingAs($agent);

    $this->patchJson(
        "/api/sales/{$sale->id}/complete",
        completionConfirmation()
    )->assertForbidden();
});

test('sale cannot complete without verified full payment', function () {
    [$admin, $agent, $sale] = completionTestSale();

    Sanctum::actingAs($admin);
    completionAddDocuments($sale);

    $this->patchJson(
        "/api/sales/{$sale->id}/complete",
        completionConfirmation()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payments');

    expect($sale->fresh()->status)->toBe('pending_completion');
    expect($sale->property->fresh()->availability_status)
        ->toBe('available');
});

test('sale cannot complete without both legal documents', function () {
    [$admin, $agent, $sale] = completionTestSale();

    Sanctum::actingAs($admin);
    completionAddFullPayment($sale);

    $this->patchJson(
        "/api/sales/{$sale->id}/complete",
        completionConfirmation()
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('documents');
});

test('administrator can complete fully verified property sale', function () {
    [$admin, $agent, $sale] = completionTestSale();

    Sanctum::actingAs($admin);

    completionAddDocuments($sale);
    completionAddFullPayment($sale);

    $this->patchJson(
        "/api/sales/{$sale->id}/complete",
        completionConfirmation()
    )
        ->assertOk()
        ->assertJsonPath('sale.status', 'completed')
        ->assertJsonPath(
            'sale.property.availability_status',
            'sold'
        );

    $sale->refresh();
    $property = $sale->property->fresh();

    expect($sale->completed_at)->not->toBeNull();
    expect($property->availability_status)->toBe('sold');
    expect((bool) $property->is_published)->toBeFalse();
    expect((bool) $property->is_featured)->toBeFalse();

    $this->assertDatabaseHas('property_sale_status_histories', [
        'property_sale_id' => $sale->id,
        'from_status' => 'pending_completion',
        'to_status' => 'completed',
        'changed_by' => $admin->id,
    ]);

    $this->patchJson(
        "/api/sales/{$sale->id}/complete",
        completionConfirmation()
    )->assertUnprocessable();
});

test('sale completion requires explicit legal confirmations', function () {
    [$admin, $agent, $sale] = completionTestSale();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/sales/{$sale->id}/complete",
        []
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'confirm_documents_reviewed',
            'confirm_transfer_registered',
        ]);
});

test('unrelated agent cannot access completion documents', function () {
    [$admin, $agent, $sale] = completionTestSale();

    $other = saleApiUser('agent');
    Sanctum::actingAs($other);

    $this->getJson(
        "/api/sales/{$sale->id}/completion-documents"
    )->assertNotFound();

    $this->postJson(
        "/api/sales/{$sale->id}/completion-documents",
        []
    )->assertForbidden();
});
