<?php

use App\Models\PropertyRentAdjustment;
use App\Models\PropertyRentPayment;
use App\Models\PropertyRentRefund;
use App\Services\PropertyRentOverpaymentService;
use App\Services\PropertyRentScheduleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function refundReservationFixture(): array
{
    if (!function_exists('leaseStatusFixture')) {
        require_once __DIR__ . '/PropertyRentalStatusTest.php';
    }

    [$admin, $agent, $property, $rental] =
        leaseStatusFixture('active');

    app(PropertyRentScheduleService::class)->generate($rental);

    $schedule = $rental->rentSchedules()
        ->orderBy('period_start')
        ->firstOrFail();

    $amount = (string) $schedule->amount_due;

    $payment = PropertyRentPayment::create([
        'reference' => 'RPAY-RES-' . bin2hex(random_bytes(6)),
        'property_rental_id' => $rental->id,
        'payment_method' => 'bank_transfer',
        'amount' => $amount,
        'currency' => $rental->currency,
        'transaction_reference' => 'RES-' . bin2hex(random_bytes(6)),
        'payment_date' => now()->toDateString(),
        'status' => 'verified',
        'recorded_by' => $admin->id,
        'verified_by' => $admin->id,
        'verified_at' => now(),
    ]);

    $payment->allocations()->create([
        'property_rent_schedule_id' => $schedule->id,
        'amount' => $amount,
    ]);

    // Credit the remaining rent, leaving a gross
    // overpayment of exactly 100000.00.
    $totalBilled = $rental->rentSchedules()->sum('amount_due');

    $credit = (float) $totalBilled
        - (float) $amount + 100000;

    PropertyRentAdjustment::create([
        'property_rental_id' => $rental->id,
        'property_rent_schedule_id' => $schedule->id,
        'reference' => 'RADJ-RES-' . bin2hex(random_bytes(6)),
        'type' => 'manual_credit',
        'amount' => number_format($credit, 2, '.', ''),
        'currency' => $rental->currency,
        'reason' => 'Refund reservation calculation fixture.',
        'effective_date' => now()->toDateString(),
        'created_by' => $admin->id,
    ]);

    return [$admin, $rental, $payment];
}

function createRefundReservation(
    $admin,
    $rental,
    $payment,
    string $amount,
    string $status
): PropertyRentRefund {
    return PropertyRentRefund::create([
        'reference' => 'RREF-' . bin2hex(random_bytes(8)),
        'property_rental_id' => $rental->id,
        'property_rent_payment_id' => $payment->id,
        'amount' => $amount,
        'currency' => $rental->currency,
        'status' => $status,
        'reason' => 'Refund reservation test.',
        'requested_by' => $admin->id,
    ]);
}

test('pending and approved refunds reduce available credit', function () {
    [$admin, $rental, $payment] = refundReservationFixture();

    createRefundReservation(
        $admin, $rental, $payment, '30000.00', 'pending'
    );

    createRefundReservation(
        $admin, $rental, $payment, '20000.00', 'approved'
    );

    $result = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($result['overpayment'])->toBe('100000.00')
        ->and($result['reserved_refunds'])->toBe('50000.00')
        ->and($result['available_refund'])->toBe('50000.00');
});

test('paid and processing refunds remain reserved', function () {
    [$admin, $rental, $payment] = refundReservationFixture();

    createRefundReservation(
        $admin, $rental, $payment, '15000.00', 'paid'
    );

    createRefundReservation(
        $admin, $rental, $payment, '25000.00', 'processing'
    );

    $result = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($result['reserved_refunds'])->toBe('40000.00')
        ->and($result['available_refund'])->toBe('60000.00');
});

test('rejected and failed refunds do not reserve credit', function () {
    [$admin, $rental, $payment] = refundReservationFixture();

    createRefundReservation(
        $admin, $rental, $payment, '20000.00', 'rejected'
    );

    createRefundReservation(
        $admin, $rental, $payment, '10000.00', 'failed'
    );

    $result = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($result['reserved_refunds'])->toBe('0.00')
        ->and($result['available_refund'])->toBe('100000.00');
});

test('refund reservations cannot produce negative availability', function () {
    [$admin, $rental, $payment] = refundReservationFixture();

    // Artificially over-reserve to test defensive calculation.
    // The future refund API must prevent this situation.
    createRefundReservation(
        $admin, $rental, $payment, '120000.00', 'pending'
    );

    $result = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($result['reserved_refunds'])->toBe('120000.00')
        ->and($result['available_refund'])->toBe('0.00');
});
