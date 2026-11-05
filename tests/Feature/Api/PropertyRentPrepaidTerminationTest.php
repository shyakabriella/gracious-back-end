<?php

use App\Services\PropertyRentScheduleService;
use App\Services\PropertyRentOverpaymentService;
use App\Services\PropertyRentStatementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

test('prepaid tenant can terminate and retain refundable credit', function () {
    if (!function_exists('leaseStatusFixture')) {
        require_once __DIR__ . '/PropertyRentalStatusTest.php';
    }

    [$admin, $agent, $property, $rental] =
        leaseStatusFixture('active');

    app(PropertyRentScheduleService::class)->generate($rental);

    $first = $rental->rentSchedules()
        ->orderBy('period_start')
        ->firstOrFail();

    $payment = $rental->payments()->create([
        'reference' => 'RPAY-PREPAID-TEST',
        'payment_method' => 'bank_transfer',
        'amount' => (string) $first->amount_due,
        'currency' => $rental->currency,
        'transaction_reference' => 'PREPAID-TEST-001',
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

    $moveOut = $first->period_start
        ->addDays(9)
        ->toDateString();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/terminate",
        [
            'reason' => 'Tenant moved out after paying in advance.',
            'move_out_date' => $moveOut,
        ]
    )->assertOk();

    expect($rental->fresh()->status)->toBe('terminated');

    expect($payment->fresh()->status)->toBe('verified');

    expect($payment->fresh()->allocations()->sum('amount'))
        ->toEqual($first->amount_due);

    $result = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect((float) $result['overpayment'])
        ->toBeGreaterThan(0);

    expect($result['outstanding'])->toBe('0.00');

    $statement = app(PropertyRentStatementService::class)
        ->build($rental);

    expect($statement['summary']['total_outstanding'])
        ->toBe('0.00');
});
