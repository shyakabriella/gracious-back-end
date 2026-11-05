<?php

use App\Models\PropertyRentRefund;
use App\Models\User;
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

function refundPayoutReconciliationFixture(): array
{
    if (!function_exists('refundProcessingFixture')) {
        require_once __DIR__ . '/PropertyRentRefundProcessingTest.php';
    }
    [$requester, $approver, $processor, $agent, $rental,
        $refundId, $url, $payload] = refundProcessingFixture();
    Sanctum::actingAs($processor);
    test()->patchJson($url, $payload)->assertOk();
    $upload = test()->post(
        "/api/rentals/{$rental->id}/refunds/{$refundId}/evidences",
        ['file' => UploadedFile::fake()->create('bank.pdf', 12, 'application/pdf')],
        ['Accept' => 'application/json']
    )->assertCreated();
    $reviewer = User::factory()->create(['status' => 'active']);
    $reviewer->assignRole('admin');
    $refund = PropertyRentRefund::findOrFail($refundId);
    $data = [
        'evidence_id' => $upload->json('evidence.id'),
        'confirmation_source' => 'bank_statement',
        'provider_transaction_reference' => $payload['transaction_reference'],
        'confirmed_amount' => (string) $refund->amount,
        'currency' => $refund->currency,
        'confirmed_recipient' => 'TEST-ACCOUNT-123456',
        'provider_confirmed_at' => now()->toDateTimeString(),
        'outcome' => 'success',
        'verification_notes' => 'Reviewed independent bank record and checked account, amount and transaction.',
        'external_record_checked' => true,
    ];
    return [$rental, $refund, $reviewer, $processor, $data];
}

test('independent manual reviewer confirms a matching refund once', function () {
    [$rental, $refund, $reviewer, $processor, $data] = refundPayoutReconciliationFixture();
    Sanctum::actingAs($reviewer);
    $url = "/api/rentals/{$rental->id}/refunds/{$refund->id}/reconcile";
    $this->postJson($url, $data)->assertOk()->assertJsonPath('refund.status', 'paid');
    $this->postJson($url, $data)->assertUnprocessable();
    $this->assertDatabaseHas('property_rent_refund_reconciliations', [
        'property_rent_refund_id' => $refund->id,
        'verified_by' => $reviewer->id,
        'outcome' => 'success',
    ]);
});

test('processor cannot confirm own refund', function () {
    [$rental, $refund, $reviewer, $processor, $data] = refundPayoutReconciliationFixture();
    Sanctum::actingAs($processor);
    $this->postJson(
        "/api/rentals/{$rental->id}/refunds/{$refund->id}/reconcile", $data
    )->assertUnprocessable()->assertJsonValidationErrors('verification');
    expect($refund->fresh()->status)->toBe('processing');
});

test('wrong destination cannot confirm payout', function () {
    [$rental, $refund, $reviewer, $processor, $data] = refundPayoutReconciliationFixture();
    Sanctum::actingAs($reviewer);
    $data['confirmed_recipient'] = 'OTHER-ACCOUNT-9999';
    $this->postJson(
        "/api/rentals/{$rental->id}/refunds/{$refund->id}/reconcile", $data
    )->assertUnprocessable()->assertJsonValidationErrors('confirmed_recipient');
    expect($refund->fresh()->status)->toBe('processing');
});
