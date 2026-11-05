<?php

use App\Models\PropertyRentRefundEvidence;
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

function refundEvidenceFixture(bool $approve = true): array
{
    if (!function_exists('refundApprovalFixture')) {
        require_once __DIR__ . '/PropertyRentRefundApprovalTest.php';
    }

    [$admin, $reviewer, $agent, $rental, $payment, $refundId] =
        refundApprovalFixture();

    if ($approve) {
        Sanctum::actingAs($reviewer);

        test()->patchJson(
            "/api/rentals/{$rental->id}/refunds/{$refundId}/approve"
        )->assertOk();
    }

    Sanctum::actingAs($admin);

    $url = "/api/rentals/{$rental->id}/refunds/{$refundId}/evidences";

    return [$admin, $reviewer, $agent, $rental, $refundId, $url];
}

test('approved refund accepts private evidence with hash', function () {
    [$admin, $reviewer, $agent, $rental, $refundId, $url] =
        refundEvidenceFixture();

    $response = $this->post($url, [
        'file' => UploadedFile::fake()->create(
            'bank-confirmation.pdf', 40, 'application/pdf'
        ),
    ], ['Accept' => 'application/json'])
        ->assertCreated();

    $evidence = PropertyRentRefundEvidence::findOrFail(
        $response->json('evidence.id')
    );

    expect($evidence->disk)->toBe('local')
        ->and($evidence->uploaded_by)->toBe($admin->id)
        ->and(strlen($evidence->sha256))->toBe(64)
        ->and($evidence->verified_at)->toBeNull();

    Storage::disk('local')->assertExists($evidence->file_path);

    $response->assertJsonMissing([
        'file_path' => $evidence->file_path,
    ]);

    $this->getJson($url)
        ->assertOk()
        ->assertJsonCount(1, 'evidences');

    $this->get(
        "{$url}/{$evidence->id}/download"
    )->assertOk();
});

test('pending refund cannot receive payout evidence', function () {
    [$admin, $reviewer, $agent, $rental, $refundId, $url] =
        refundEvidenceFixture(false);

    $this->post($url, [
        'file' => UploadedFile::fake()->create(
            'receipt.pdf', 20, 'application/pdf'
        ),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('refund');

    $this->assertDatabaseCount(
        'property_rent_refund_evidences', 0
    );
});

test('agent cannot upload or view refund evidence', function () {
    [$admin, $reviewer, $agent, $rental, $refundId, $url] =
        refundEvidenceFixture();

    Sanctum::actingAs($agent);

    $this->getJson($url)->assertForbidden();

    $this->post($url, [
        'file' => UploadedFile::fake()->create(
            'receipt.pdf', 20, 'application/pdf'
        ),
    ], ['Accept' => 'application/json'])
        ->assertForbidden();
});

test('refund evidence rejects unsupported file formats', function () {
    [$admin, $reviewer, $agent, $rental, $refundId, $url] =
        refundEvidenceFixture();

    $this->post($url, [
        'file' => UploadedFile::fake()->create(
            'script.txt', 20, 'text/plain'
        ),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

test('changed evidence fails integrity verification', function () {
    [$admin, $reviewer, $agent, $rental, $refundId, $url] =
        refundEvidenceFixture();

    $response = $this->post($url, [
        'file' => UploadedFile::fake()->create(
            'receipt.pdf', 20, 'application/pdf'
        ),
    ], ['Accept' => 'application/json'])
        ->assertCreated();

    $evidence = PropertyRentRefundEvidence::findOrFail(
        $response->json('evidence.id')
    );

    Storage::disk('local')->put(
        $evidence->file_path,
        'This file was changed after uploading.'
    );

    $this->get(
        "{$url}/{$evidence->id}/download"
    )->assertStatus(409);
});

test('evidence cannot be downloaded through another refund', function () {
    [$admin, $reviewer, $agent, $rental, $refundId, $url] =
        refundEvidenceFixture();

    $response = $this->post($url, [
        'file' => UploadedFile::fake()->create(
            'receipt.pdf', 20, 'application/pdf'
        ),
    ], ['Accept' => 'application/json'])
        ->assertCreated();

    $evidenceId = $response->json('evidence.id');

    // Another refund belonging to the same rental.
    $otherRefund = \App\Models\PropertyRentRefund::create([
        'reference' => 'RREF-OTHER-' . bin2hex(random_bytes(5)),
        'property_rental_id' => $rental->id,
        'property_rent_payment_id' =>
            \App\Models\PropertyRentRefund::findOrFail(
                $refundId
            )->property_rent_payment_id,
        'amount' => '1.00',
        'currency' => $rental->currency,
        'status' => 'pending',
        'reason' => 'Cross-refund access security test.',
        'requested_by' => $admin->id,
    ]);

    $this->get(
        "/api/rentals/{$rental->id}/refunds/"
        . "{$otherRefund->id}/evidences/"
        . "{$evidenceId}/download"
    )->assertNotFound();
});
