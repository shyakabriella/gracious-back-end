<?php

use App\Services\PropertyRentPaymentAllocationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function reconciliationFixture(): array
{
    if (!function_exists('leaseStatusFixture')) {
        require_once __DIR__ . '/PropertyRentalStatusTest.php';
    }

    [$admin, $agent, $property, $rental] =
        leaseStatusFixture('active');

    app(\App\Services\PropertyRentScheduleService::class)
        ->generate($rental);

    return [$admin, $agent, $property, $rental];
}

function reconciliationPayment(
    $rental,
    $admin,
    string $amount,
    string $status = 'pending'
) {
    return $rental->payments()->create([
        'reference' => 'RPAY-' . strtoupper(bin2hex(random_bytes(8))),
        'payment_method' => 'bank_transfer',
        'amount' => $amount,
        'currency' => $rental->currency,
        'transaction_reference' => 'TX-'
            . strtoupper(bin2hex(random_bytes(8))),
        'payment_date' => now()->toDateString(),
        'status' => $status,
        'recorded_by' => $admin->id,
    ]);
}

test('verified allocations equal payment amount', function () {
    [$admin, $agent, $property, $rental] =
        reconciliationFixture();

    $payment = reconciliationPayment(
        $rental, $admin, '400000.00'
    );

    \Illuminate\Support\Facades\DB::transaction(function () use (
        $rental, $payment
    ) {
        app(PropertyRentPaymentAllocationService::class)
            ->allocate($rental, $payment);

        $payment->update(['status' => 'verified']);
    });

    expect(
        number_format(
            (float) $payment->allocations()->sum('amount'),
            2, '.', ''
        )
    )->toBe('400000.00');

    expect($payment->allocations()->count())->toBe(2);
});

test('pending and rejected payments never reduce verified balance', function () {
    [$admin, $agent, $property, $rental] =
        reconciliationFixture();

    reconciliationPayment(
        $rental, $admin, '100000.00', 'pending'
    );

    reconciliationPayment(
        $rental, $admin, '50000.00', 'rejected'
    );

    Sanctum::actingAs($admin);

    $this->getJson("/api/rentals/{$rental->id}/payments")
        ->assertOk()
        ->assertJsonPath('summary.verified_paid', '0.00');
});

test('verified partial payment reduces outstanding balance correctly', function () {
    [$admin, $agent, $property, $rental] =
        reconciliationFixture();

    $payment = reconciliationPayment(
        $rental, $admin, '100000.00'
    );

    \Illuminate\Support\Facades\DB::transaction(function () use (
        $rental, $payment
    ) {
        app(PropertyRentPaymentAllocationService::class)
            ->allocate($rental, $payment);

        $payment->update(['status' => 'verified']);
    });

    Sanctum::actingAs($admin);

    $response = $this->getJson(
        "/api/rentals/{$rental->id}/payments"
    )->assertOk();

    $billed = (float) $response->json('summary.total_billed');
    $balance = (float) $response->json(
        'summary.outstanding_balance'
    );

    expect(round($billed - $balance, 2))
        ->toBe(100000.0);
});

test('payment allocations belong to the same rental agreement', function () {
    [$admin, $agent, $property, $rental] =
        reconciliationFixture();

    $payment = reconciliationPayment(
        $rental, $admin, '100000.00'
    );

    \Illuminate\Support\Facades\DB::transaction(function () use (
        $rental, $payment
    ) {
        app(PropertyRentPaymentAllocationService::class)
            ->allocate($rental, $payment);

        $payment->update(['status' => 'verified']);
    });

    $wrong = \Illuminate\Support\Facades\DB::table(
        'property_rent_payment_allocations as a'
    )
        ->join(
            'property_rent_payments as p',
            'p.id', '=', 'a.property_rent_payment_id'
        )
        ->join(
            'property_rent_schedules as s',
            's.id', '=', 'a.property_rent_schedule_id'
        )
        ->whereColumn(
            'p.property_rental_id',
            '<>',
            's.property_rental_id'
        )
        ->count();

    expect($wrong)->toBe(0);
});
