<?php

use App\Models\PropertyRentPayment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function rentVerificationFixture(
    string $amount = '100000.00',
    bool $withEvidence = true
): array {
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
        'amount' => $amount,
        'currency' => $rental->currency,
        'transaction_reference' => 'TX-'
            . strtoupper(bin2hex(random_bytes(8))),
        'payment_date' => now()->toDateString(),
        'status' => 'pending',
        'recorded_by' => $admin->id,
    ]);

    if ($withEvidence) {
        $payment->evidences()->create([
            'original_name' => 'receipt.pdf',
            'disk' => 'local',
            'file_path' => 'rent-payment-evidence/test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'uploaded_by' => $admin->id,
        ]);
    }

    return [$admin, $agent, $property, $rental, $payment];
}

test('verification requires authentication', function () {
    $this->patchJson(
        '/api/rentals/1/payments/1/verify'
    )->assertUnauthorized();
});

test('admin can verify and allocate rental payment', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentVerificationFixture();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )
        ->assertOk()
        ->assertJsonPath('payment.status', 'verified');

    expect($payment->fresh()->verified_at)->not->toBeNull();

    expect($payment->allocations()->count())->toBe(1);

    expect((string) $payment->allocations()->first()->amount)
        ->toBe('100000.00');

    $this->getJson("/api/rentals/{$rental->id}/payments")
        ->assertOk()
        ->assertJsonPath('summary.verified_paid', '100000.00');

    $this->assertDatabaseHas(
        'property_rent_payment_status_histories',
        [
            'property_rent_payment_id' => $payment->id,
            'from_status' => 'pending',
            'to_status' => 'verified',
            'changed_by' => $admin->id,
        ]
    );
});

test('payment cannot be verified without evidence', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentVerificationFixture('100000.00', false);

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('evidence');

    expect($payment->fresh()->status)->toBe('pending');
    expect($payment->allocations()->count())->toBe(0);
});

test('assigned agent and property manager cannot verify payments', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentVerificationFixture();

    Sanctum::actingAs($agent);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )->assertForbidden();

    Sanctum::actingAs(leaseStatusUser('property_manager'));

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )->assertForbidden();

    expect($payment->fresh()->status)->toBe('pending');
});

test('verified payment cannot be verified a second time', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentVerificationFixture();

    Sanctum::actingAs($admin);

    $url = "/api/rentals/{$rental->id}/payments/{$payment->id}/verify";

    $this->patchJson($url)->assertOk();

    $this->patchJson($url)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($payment->allocations()->count())->toBe(1);
});

test('verified payment is allocated to oldest unpaid bill first', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentVerificationFixture('400000.00');

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )->assertOk();

    $allocations = $payment->allocations()
        ->with('schedule')
        ->get()
        ->sortBy(fn ($allocation) =>
            $allocation->schedule->period_start->toDateString()
        )
        ->values();

    expect($allocations)->toHaveCount(2);

    expect((string) $allocations[0]->amount)->toBe('350000.00');
    expect((string) $allocations[1]->amount)->toBe('50000.00');
});

test('payment exceeding unpaid rent is rejected without allocations', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentVerificationFixture();

    $total = $rental->rentSchedules()->sum('amount_due');

    $payment->forceFill([
        'amount' => number_format($total + 1, 2, '.', ''),
    ])->save();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect($payment->fresh()->status)->toBe('pending');
    expect($payment->allocations()->count())->toBe(0);
});

test('admin can reject payment without affecting balance', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentVerificationFixture();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/reject",
        ['reason' => 'No matching transaction was received.']
    )
        ->assertOk()
        ->assertJsonPath('payment.status', 'rejected');

    expect($payment->allocations()->count())->toBe(0);

    $this->getJson("/api/rentals/{$rental->id}/payments")
        ->assertJsonPath('summary.verified_paid', '0.00');
});

test('payment audit history is visible to assigned agent', function () {
    [$admin, $agent, $property, $rental, $payment] =
        rentVerificationFixture();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )->assertOk();

    Sanctum::actingAs($agent);

    $this->getJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/history"
    )
        ->assertOk()
        ->assertJsonPath('history.0.to_status', 'verified');

    Sanctum::actingAs(leaseStatusUser('agent'));

    $this->getJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/history"
    )->assertNotFound();
});
