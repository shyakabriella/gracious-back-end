<?php

use App\Services\PropertyRentScheduleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function terminationWorkflowFixture(): array
{
    if (!function_exists('leaseStatusFixture')) {
        require_once __DIR__ . '/PropertyRentalStatusTest.php';
    }

    [$admin, $agent, $property, $rental] =
        leaseStatusFixture('active');

    app(PropertyRentScheduleService::class)->generate($rental);

    return [$admin, $agent, $property, $rental];
}

test('termination credits future rent and releases the property', function () {
    [$admin, $agent, $property, $rental] =
        terminationWorkflowFixture();

    $schedules = $rental->rentSchedules()
        ->orderBy('period_start')
        ->get();

    $moveOut = $schedules[0]->period_end->toDateString();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/terminate",
        [
            'reason' => 'Tenant moved out before lease completion.',
            'move_out_date' => $moveOut,
        ]
    )
        ->assertOk()
        ->assertJsonPath('rental.status', 'terminated')
        ->assertJsonPath(
            'rental.property.availability_status',
            'available'
        );

    expect(
        $rental->adjustments()
            ->where('type', 'early_termination')
            ->count()
    )->toBe($schedules->count() - 1);

    expect($property->fresh()->is_published)->toBeFalse();
});

test('termination prorates the move out billing period', function () {
    [$admin, $agent, $property, $rental] =
        terminationWorkflowFixture();

    $first = $rental->rentSchedules()
        ->orderBy('period_start')
        ->firstOrFail();

    $moveOut = $first->period_start->addDays(9)->toDateString();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/terminate",
        [
            'reason' => 'Tenant returned keys and moved out.',
            'move_out_date' => $moveOut,
        ]
    )->assertOk();

    $credit = $first->adjustments()
        ->where('type', 'early_termination')
        ->firstOrFail();

    expect((float) $credit->amount)->toBeGreaterThan(0);

    $this->getJson(
        "/api/rentals/{$rental->id}/statement"
    )
        ->assertOk()
        ->assertJsonPath(
            'statement.periods.1.revised_amount',
            '0.00'
        );
});

test('failed termination rolls back lease and financial changes', function () {
    [$admin, $agent, $property, $rental] =
        terminationWorkflowFixture();

    $schedules = $rental->rentSchedules()
        ->orderBy('period_start')
        ->get();

    expect($schedules->count())->toBeGreaterThanOrEqual(2);

    $first = $schedules[0];
    $second = $schedules[1];

    $payment = $rental->payments()->create([
        'reference' => 'RPAY-ROLLBACK-TEST',
        'payment_method' => 'cash',
        'amount' => (string) $first->amount_due,
        'currency' => $rental->currency,
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

    // An existing credit on the second period means that
    // another full termination credit would make rent negative.
    $existingCredit = $rental->adjustments()->create([
        'property_rent_schedule_id' => $second->id,
        'reference' => 'RADJ-ROLLBACK-TEST',
        'type' => 'manual_credit',
        'amount' => '1.00',
        'currency' => $rental->currency,
        'reason' => 'Existing billing correction.',
        'effective_date' => now()->toDateString(),
        'created_by' => $admin->id,
    ]);

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/terminate",
        [
            'reason' => 'Tenant requested early termination.',
            'move_out_date' => $first->period_start
                ->addDays(5)->toDateString(),
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('move_out_date');

    expect($rental->fresh()->status)->toBe('active');
    expect($property->fresh()->availability_status)->toBe('rented');

    expect(
        $rental->adjustments()
            ->where('type', 'early_termination')
            ->count()
    )->toBe(0);

    expect($existingCredit->fresh())->not->toBeNull();
    expect($payment->fresh()->status)->toBe('verified');

    expect(
        (int) round(
            (float) $payment->allocations()->sum('amount') * 100
        )
    )->toBe(
        (int) round((float) $first->amount_due * 100)
    );
});

test('termination cannot be applied twice', function () {
    [$admin, $agent, $property, $rental] =
        terminationWorkflowFixture();

    $date = $rental->rentSchedules()
        ->orderBy('period_start')
        ->firstOrFail()
        ->period_end
        ->toDateString();

    Sanctum::actingAs($admin);

    $url = "/api/rentals/{$rental->id}/terminate";

    $payload = [
        'reason' => 'Tenant vacated property early.',
        'move_out_date' => $date,
    ];

    $this->patchJson($url, $payload)->assertOk();

    $count = $rental->adjustments()->count();

    $this->patchJson($url, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($rental->adjustments()->count())->toBe($count);
});

test('termination requires actual move out date', function () {
    [$admin, $agent, $property, $rental] =
        terminationWorkflowFixture();

    Sanctum::actingAs($admin);

    $this->patchJson(
        "/api/rentals/{$rental->id}/terminate",
        ['reason' => 'Tenant moved out early.']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('move_out_date');

    expect($rental->fresh()->status)->toBe('active');
});
