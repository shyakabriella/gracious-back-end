<?php

use App\Models\PropertyRentAdjustment;
use App\Models\PropertyRentPayment;
use App\Services\PropertyRentOverpaymentService;
use App\Services\PropertyRentScheduleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function overpaymentFixture(): array
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

    return [$admin, $rental, $schedule];
}

function verifiedRentForSchedule(
    $admin,
    $rental,
    $schedule,
    string $amount
): void {
    $payment = PropertyRentPayment::create([
        'reference' => 'TEST-RPAY-' . bin2hex(random_bytes(8)),
        'property_rental_id' => $rental->id,
        'payment_method' => 'bank_transfer',
        'amount' => $amount,
        'currency' => $rental->currency,
        'transaction_reference' => 'TEST-' . bin2hex(random_bytes(8)),
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
}

test('unpaid rental has outstanding balance but no overpayment', function () {
    [$admin, $rental, $schedule] = overpaymentFixture();

    $result = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($result['verified_paid'])->toBe('0.00')
        ->and($result['overpayment'])->toBe('0.00')
        ->and($result['outstanding'])->toBe($result['net_billed']);
});

test('fully paid rental has no overpayment', function () {
    [$admin, $rental, $schedule] = overpaymentFixture();

    $total = $rental->rentSchedules()->sum('amount_due');

    verifiedRentForSchedule(
        $admin,
        $rental,
        $schedule,
        (string) $total
    );

    $result = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($result['overpayment'])->toBe('0.00')
        ->and($result['outstanding'])->toBe('0.00')
        ->and($result['verified_paid'])->toBe($result['net_billed']);
});

test('verified payment above adjusted rent produces overpayment', function () {
    [$admin, $rental, $schedule] = overpaymentFixture();

    $total = (string) $rental->rentSchedules()->sum('amount_due');

    verifiedRentForSchedule(
        $admin,
        $rental,
        $schedule,
        $total
    );

    PropertyRentAdjustment::create([
        'property_rental_id' => $rental->id,
        'property_rent_schedule_id' => $schedule->id,
        'reference' => 'TEST-ADJ-' . bin2hex(random_bytes(8)),
        'type' => 'manual_credit',
        'amount' => '1000.00',
        'currency' => $rental->currency,
        'reason' => 'Test credit for overpayment calculation.',
        'effective_date' => now()->toDateString(),
        'created_by' => $admin->id,
    ]);

    $result = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($result['overpayment'])->toBe('1000.00')
        ->and($result['outstanding'])->toBe('0.00')
        ->and($result['total_credits'])->toBe('1000.00');
});
