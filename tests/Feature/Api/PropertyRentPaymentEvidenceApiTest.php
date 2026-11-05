<?php

use App\Models\PropertyRentPayment;
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

function rentEvidenceFixture(): array
{
    if (!function_exists('leaseStatusFixture')) {
        require_once __DIR__ . '/PropertyRentalStatusTest.php';
    }

    [$admin, $agent, $property, $rental] =
        leaseStatusFixture('active');

    app(\App\Services\PropertyRentScheduleService::class)
        ->generate($rental);

    $payment = $rental->payments()->create([
        'reference' => 'RPAY-' . strtoupper(bin2hex(random_bytes(8))),
        'payment_method' => 'bank_transfer',
        'amount' => '100000.00',
        'currency' => $rental->currency,
        'transaction_reference' => 'TX-'
            . strtoupper(bin2hex(random_bytes(8))),
        'payment_date' => now()->toDateString(),
        'status' => 'pending',
        'recorded_by' => $admin->id,
    ]);

    return [$admin, $agent, $property, $rental, $payment];
}

test('rental receipt routes require authentication', function () {
    $this->getJson(
        '/api/rentals/1/payments/1/evidences'
    )->assertUnauthorized();
});

test('admin can upload a private rental payment receipt', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentEvidenceFixture();

    Sanctum::actingAs($admin);

    $response = $this->post(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/evidences",
        [
            'file' => UploadedFile::fake()->create(
                'receipt.pdf', 30, 'application/pdf'
            ),
        ],
        ['Accept' => 'application/json']
    );

    $response->assertCreated()
        ->assertJsonPath('evidence.original_name', 'receipt.pdf')
        ->assertJsonMissingPath('evidence.file_path')
        ->assertJsonMissingPath('evidence.disk');

    $evidence = $payment->evidences()->firstOrFail();

    Storage::disk('local')->assertExists($evidence->file_path);
    expect($evidence->disk)->toBe('local');
});

test('agent cannot upload rental payment receipts', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentEvidenceFixture();

    Sanctum::actingAs($agent);

    $this->post(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/evidences",
        [
            'file' => UploadedFile::fake()->create(
                'receipt.pdf', 20, 'application/pdf'
            ),
        ],
        ['Accept' => 'application/json']
    )->assertForbidden();

    expect($payment->evidences()->count())->toBe(0);
});

test('assigned agent can view receipt metadata without paths', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentEvidenceFixture();

    $payment->evidences()->create([
        'original_name' => 'receipt.pdf',
        'disk' => 'local',
        'file_path' => 'private/test-receipt.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'uploaded_by' => $admin->id,
    ]);

    Sanctum::actingAs($agent);

    $this->getJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/evidences"
    )
        ->assertOk()
        ->assertJsonCount(1, 'evidences')
        ->assertJsonMissingPath('evidences.0.file_path')
        ->assertJsonMissingPath('evidences.0.disk');
});

test('unrelated agent cannot view rental payment receipts', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentEvidenceFixture();

    $otherAgent = leaseStatusUser('agent');

    Sanctum::actingAs($otherAgent);

    $this->getJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/evidences"
    )->assertNotFound();
});

test('receipt from another payment cannot be downloaded', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentEvidenceFixture();

    $anotherPayment = $rental->payments()->create([
        'reference' => 'RPAY-OTHER-'
            . strtoupper(bin2hex(random_bytes(4))),
        'payment_method' => 'cash',
        'amount' => '50000.00',
        'currency' => $rental->currency,
        'payment_date' => now()->toDateString(),
        'status' => 'pending',
        'recorded_by' => $admin->id,
    ]);

    $evidence = $anotherPayment->evidences()->create([
        'original_name' => 'other.pdf',
        'disk' => 'local',
        'file_path' => 'rent-payment-evidence/other.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'uploaded_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $this->get(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/evidences/{$evidence->id}/download"
    )->assertNotFound();
});

test('verified payment cannot accept new receipts', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentEvidenceFixture();

    $payment->update(['status' => 'verified']);

    Sanctum::actingAs($admin);

    $this->post(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/evidences",
        [
            'file' => UploadedFile::fake()->create(
                'receipt.pdf', 20, 'application/pdf'
            ),
        ],
        ['Accept' => 'application/json']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect($payment->evidences()->count())->toBe(0);
});

test('invalid rental receipt file type is rejected', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentEvidenceFixture();

    Sanctum::actingAs($admin);

    $this->post(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/evidences",
        [
            'file' => UploadedFile::fake()->create(
                'malware.exe', 20, 'application/octet-stream'
            ),
        ],
        ['Accept' => 'application/json']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});
