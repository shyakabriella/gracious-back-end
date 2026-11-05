<?php

use App\Services\PropertyRentPaymentAllocationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function statementFixture(): array
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

function statementVerifyPayment(
    $rental,
    $admin,
    string $amount
): void {
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

    DB::transaction(function () use ($rental, $payment, $admin) {
        $lockedRental = \App\Models\PropertyRental::query()
            ->lockForUpdate()
            ->findOrFail($rental->id);

        app(PropertyRentPaymentAllocationService::class)
            ->allocate($lockedRental, $payment);

        $payment->forceFill([
            'status' => 'verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ])->save();
    });
}

test('rental statements require authentication', function () {
    $this->getJson(
        '/api/rentals/1/statement'
    )->assertUnauthorized();
});

test('admin can view rental statement and totals reconcile', function () {
    [$admin, $agent, $property, $rental] =
        statementFixture();

    Sanctum::actingAs($admin);

    $response = $this->getJson(
        "/api/rentals/{$rental->id}/statement"
    )->assertOk();

    $summary = $response->json('statement.summary');

    expect(
        round(
            (float) $summary['verified_paid']
            + (float) $summary['total_outstanding'],
            2
        )
    )->toBe(
        round((float) $summary['total_billed'], 2)
    );

    expect($response->json('statement.periods'))
        ->not->toBeEmpty();
});

test('verified partial payment appears in statement', function () {
    [$admin, $agent, $property, $rental] =
        statementFixture();

    statementVerifyPayment(
        $rental, $admin, '100000.00'
    );

    Sanctum::actingAs($admin);

    $response = $this->getJson(
        "/api/rentals/{$rental->id}/statement"
    )->assertOk()
        ->assertJsonPath(
            'statement.summary.verified_paid',
            '100000.00'
        );

    $first = $response->json('statement.periods.0');

    expect($first['verified_paid'])->toBe('100000.00');
    expect($first['balance'])->toBe('250000.00');
});

test('statement correctly separates overdue due today and future', function () {
    [$admin, $agent, $property, $rental] =
        statementFixture();

    $schedules = $rental->rentSchedules()
        ->orderBy('period_start')
        ->get();

    expect($schedules->count())->toBeGreaterThanOrEqual(3);

    // Set a fixed date matching the second bill's due date.
    $asOf = $schedules[1]->due_date->toDateString();

    Sanctum::actingAs($admin);

    $response = $this->getJson(
        "/api/rentals/{$rental->id}/statement?as_of={$asOf}"
    )->assertOk();

    $periods = $response->json('statement.periods');

    expect($periods[0]['bucket'])->toBe('overdue');
    expect($periods[1]['bucket'])->toBe('due_today');
    expect($periods[2]['bucket'])->toBe('future');
});

test('assigned agent can view statement but unrelated agent cannot', function () {
    [$admin, $agent, $property, $rental] =
        statementFixture();

    Sanctum::actingAs($agent);

    $this->getJson(
        "/api/rentals/{$rental->id}/statement"
    )->assertOk();

    Sanctum::actingAs(leaseStatusUser('agent'));

    $this->getJson(
        "/api/rentals/{$rental->id}/statement"
    )->assertNotFound();
});

test('customer cannot access private rental statement', function () {
    [$admin, $agent, $property, $rental] =
        statementFixture();

    Sanctum::actingAs(leaseStatusUser('customer'));

    $this->getJson(
        "/api/rentals/{$rental->id}/statement"
    )->assertNotFound();
});

test('invalid statement date is rejected', function () {
    [$admin, $agent, $property, $rental] =
        statementFixture();

    Sanctum::actingAs($admin);

    $this->getJson(
        "/api/rentals/{$rental->id}/statement?as_of=not-a-date"
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('as_of');
});
