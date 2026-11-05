<?php

use App\Models\PropertyRentPayment;
use App\Services\PropertyRentOverpaymentService;
use App\Services\PropertyRentScheduleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function refundApiFixture(): array
{
    if (!function_exists('leaseStatusFixture')) {
        require_once __DIR__ . '/PropertyRentalStatusTest.php';
    }

    [$admin, $agent, $property, $rental] =
        leaseStatusFixture('active');

    app(PropertyRentScheduleService::class)->generate($rental);

    $first = $rental->rentSchedules()
        ->orderBy('period_start')
        ->firstOrFail();

    $payment = PropertyRentPayment::create([
        'reference' => 'RPAY-REFUND-' . bin2hex(random_bytes(6)),
        'property_rental_id' => $rental->id,
        'payment_method' => 'bank_transfer',
        'amount' => (string) $first->amount_due,
        'currency' => $rental->currency,
        'transaction_reference' => 'RF-' . bin2hex(random_bytes(6)),
        'payment_date' => now()->toDateString(),
        'status' => 'verified',
        'recorded_by' => $admin->id,
        'verified_by' => $admin->id,
        'verified_at' => now(),
    ]);

    $payment->allocations()->create([
        'property_rent_schedule_id' => $first->id,
        'amount' => (string) $first->amount_due,
    ]);

    Sanctum::actingAs($admin);

    test()->patchJson(
        "/api/rentals/{$rental->id}/terminate",
        [
            'reason' => 'Tenant moved out after prepaid rent.',
            'move_out_date' => $first->period_start
                ->addDays(9)->toDateString(),
        ]
    )->assertOk();

    return [$admin, $agent, $rental, $payment];
}

test('refund endpoints require authentication', function () {
    $this->getJson('/api/rentals/999/refunds')
        ->assertUnauthorized();

    $this->postJson('/api/rentals/999/refunds', [])
        ->assertUnauthorized();
});

test('admin can request a pending refund with audit history', function () {
    [$admin, $agent, $rental, $payment] = refundApiFixture();

    $url = "/api/rentals/{$rental->id}/refunds";

    $response = $this->postJson($url, [
        'property_rent_payment_id' => $payment->id,
        'amount' => '10000.00',
        'reason' => 'Unused prepaid rent after move-out.',
    ])->assertCreated()
      ->assertJsonPath('refund.status', 'pending');

    $refundId = $response->json('refund.id');

    $this->assertDatabaseHas('property_rent_refund_status_histories', [
        'property_rent_refund_id' => $refundId,
        'to_status' => 'pending',
        'changed_by' => $admin->id,
    ]);

    $this->getJson($url)
        ->assertOk()
        ->assertJsonCount(1, 'refunds');

    $balance = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($balance['reserved_refunds'])->toBe('10000.00');
});

test('refund requests cannot exceed the available credit', function () {
    [$admin, $agent, $rental, $payment] = refundApiFixture();

    $this->postJson(
        "/api/rentals/{$rental->id}/refunds",
        [
            'property_rent_payment_id' => $payment->id,
            'amount' => '999999999.00',
            'reason' => 'Attempt above refundable balance.',
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

test('multiple refund requests cannot reserve the same credit', function () {
    [$admin, $agent, $rental, $payment] = refundApiFixture();

    $url = "/api/rentals/{$rental->id}/refunds";

    $available = app(PropertyRentOverpaymentService::class)
        ->calculate($rental)['available_refund'];

    $payload = [
        'property_rent_payment_id' => $payment->id,
        'amount' => $available,
        'reason' => 'Refund the available prepaid balance.',
    ];

    $this->postJson($url, $payload)->assertCreated();

    $this->postJson($url, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    $this->assertDatabaseCount('property_rent_refunds', 1);
});

test('refund requires a verified payment from the same rental', function () {
    [$admin, $agent, $rental, $payment] = refundApiFixture();

    $other = $rental->payments()->create([
        'reference' => 'RPAY-PENDING-' . bin2hex(random_bytes(6)),
        'payment_method' => 'cash',
        'amount' => '1000.00',
        'currency' => $rental->currency,
        'payment_date' => now()->toDateString(),
        'status' => 'pending',
        'recorded_by' => $admin->id,
    ]);

    $this->postJson(
        "/api/rentals/{$rental->id}/refunds",
        [
            'property_rent_payment_id' => $other->id,
            'amount' => '1000.00',
            'reason' => 'Attempt to refund unverified payment.',
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_rent_payment_id');
});

test('agent cannot request refunds', function () {
    [$admin, $agent, $rental, $payment] = refundApiFixture();

    Sanctum::actingAs($agent);

    $this->postJson(
        "/api/rentals/{$rental->id}/refunds",
        [
            'property_rent_payment_id' => $payment->id,
            'amount' => '1000.00',
            'reason' => 'Unauthorized refund request.',
        ]
    )->assertForbidden();
});
