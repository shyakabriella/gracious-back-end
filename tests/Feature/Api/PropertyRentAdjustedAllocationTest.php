<?php

use App\Models\PropertyRental;
use App\Services\PropertyRentScheduleService;
use App\Services\PropertyRentTerminationAdjustmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function adjustedAllocationFixture(): array
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

    \Illuminate\Support\Facades\DB::transaction(function () use (
        $rental, $admin, $first
    ) {
        $locked = PropertyRental::query()
            ->lockForUpdate()
            ->findOrFail($rental->id);

        app(PropertyRentTerminationAdjustmentService::class)
            ->apply(
                $locked,
                $first->period_end->toDateString(),
                $admin->id,
                'Tenant moved out after the first billing period.'
            );
    });

    return [$admin, $rental, $first];
}

function adjustedAllocationPayment(
    $rental,
    $admin,
    string $amount
) {
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

    $payment->evidences()->create([
        'original_name' => 'receipt.pdf',
        'disk' => 'local',
        'file_path' => 'rent-payment-evidence/test.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'uploaded_by' => $admin->id,
    ]);

    return $payment;
}

test('payment exceeding adjusted balance cannot be verified', function () {
    [$admin, $rental, $first] =
        adjustedAllocationFixture();

    $amount = number_format(
        (float) $first->amount_due + 1,
        2,
        '.',
        ''
    );

    $payment = adjustedAllocationPayment(
        $rental,
        $admin,
        $amount
    );

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect($payment->fresh()->status)->toBe('pending');
    expect($payment->allocations()->count())->toBe(0);
});

test('payment equal to adjusted balance can be verified', function () {
    [$admin, $rental, $first] =
        adjustedAllocationFixture();

    $payment = adjustedAllocationPayment(
        $rental,
        $admin,
        (string) $first->amount_due
    );

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )
        ->assertOk()
        ->assertJsonPath('payment.status', 'verified');

    expect($payment->allocations()->count())->toBe(1);

    expect((string) $payment->allocations()->first()->amount)
        ->toBe((string) $first->amount_due);
});

test('adjusted statement shows zero outstanding after full payment', function () {
    [$admin, $rental, $first] =
        adjustedAllocationFixture();

    $payment = adjustedAllocationPayment(
        $rental,
        $admin,
        (string) $first->amount_due
    );

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/payments/{$payment->id}/verify"
    )->assertOk();

    $this->getJson(
        "/api/rentals/{$rental->id}/statement"
    )
        ->assertOk()
        ->assertJsonPath(
            'statement.summary.total_outstanding',
            '0.00'
        );
});
