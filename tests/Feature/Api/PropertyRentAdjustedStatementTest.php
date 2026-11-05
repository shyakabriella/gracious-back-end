<?php

use App\Models\PropertyRental;
use App\Services\PropertyRentScheduleService;
use App\Services\PropertyRentStatementService;
use App\Services\PropertyRentTerminationAdjustmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function adjustedStatementFixture(): array
{
    if (!function_exists('leaseStatusFixture')) {
        require_once __DIR__ . '/PropertyRentalStatusTest.php';
    }

    [$admin, $agent, $property, $rental] =
        leaseStatusFixture('active');

    app(PropertyRentScheduleService::class)->generate($rental);

    return [$admin, $rental];
}

function creditUnusedRent(
    PropertyRental $rental,
    int $adminId,
    string $date
): void {
    DB::transaction(function () use ($rental, $adminId, $date) {
        $locked = PropertyRental::query()
            ->lockForUpdate()
            ->findOrFail($rental->id);

        app(PropertyRentTerminationAdjustmentService::class)
            ->apply(
                $locked,
                $date,
                $adminId,
                'Tenant moved out early.'
            );
    });
}

test('future rent becomes zero after termination credits', function () {
    [$admin, $rental] = adjustedStatementFixture();

    $schedules = $rental->rentSchedules()
        ->orderBy('period_start')
        ->get();

    $original = (string) $schedules[1]->amount_due;

    creditUnusedRent(
        $rental,
        $admin->id,
        $schedules[0]->period_end->toDateString()
    );

    $statement = app(PropertyRentStatementService::class)
        ->build($rental);

    $future = $statement['periods'][1];

    expect($future['amount_due'])->toBe($original);
    expect($future['credits'])->toBe($original);
    expect($future['revised_amount'])->toBe('0.00');
    expect($future['balance'])->toBe('0.00');
});

test('partial month credit reduces revised bill correctly', function () {
    [$admin, $rental] = adjustedStatementFixture();

    $first = $rental->rentSchedules()
        ->orderBy('period_start')
        ->firstOrFail();

    $moveOut = $first->period_start
        ->addDays(9)
        ->toDateString();

    creditUnusedRent($rental, $admin->id, $moveOut);

    $statement = app(PropertyRentStatementService::class)
        ->build($rental);

    $period = $statement['periods'][0];

    $original = (int) round((float) $period['amount_due'] * 100);
    $credit = (int) round((float) $period['credits'] * 100);
    $revised = (int) round((float) $period['revised_amount'] * 100);

    expect($revised)->toBe($original - $credit);
    expect($period['balance'])->toBe($period['revised_amount']);
});

test('statement totals reconcile after termination credits', function () {
    [$admin, $rental] = adjustedStatementFixture();

    $first = $rental->rentSchedules()
        ->orderBy('period_start')
        ->firstOrFail();

    creditUnusedRent(
        $rental,
        $admin->id,
        $first->period_start->addDays(5)->toDateString()
    );

    $summary = app(PropertyRentStatementService::class)
        ->build($rental)['summary'];

    $original = (float) $summary['total_billed'];
    $credits = (float) $summary['total_credits'];
    $debits = (float) $summary['total_debits'];
    $net = (float) $summary['net_billed'];
    $paid = (float) $summary['verified_paid'];
    $outstanding = (float) $summary['total_outstanding'];

    expect(round($original + $debits - $credits, 2))
        ->toBe(round($net, 2));

    expect(round($net - $paid, 2))
        ->toBe(round($outstanding, 2));
});

test('verified partial payment remains in adjusted statement', function () {
    [$admin, $rental] = adjustedStatementFixture();

    $first = $rental->rentSchedules()
        ->orderBy('period_start')
        ->firstOrFail();

    $payment = $rental->payments()->create([
        'reference' => 'RPAY-ADJUSTED-STMT',
        'payment_method' => 'cash',
        'amount' => '50000.00',
        'currency' => $rental->currency,
        'payment_date' => now()->toDateString(),
        'status' => 'verified',
        'recorded_by' => $admin->id,
        'verified_by' => $admin->id,
        'verified_at' => now(),
    ]);

    $payment->allocations()->create([
        'property_rent_schedule_id' => $first->id,
        'amount' => '50000.00',
    ]);

    creditUnusedRent(
        $rental,
        $admin->id,
        $first->period_start->addDays(9)->toDateString()
    );

    $statement = app(PropertyRentStatementService::class)
        ->build($rental);

    $period = $statement['periods'][0];

    $revised = (float) $period['revised_amount'];
    $paid = (float) $period['verified_paid'];
    $balance = (float) $period['balance'];

    expect($period['verified_paid'])->toBe('50000.00');
    expect(round($revised - $paid, 2))
        ->toBe(round($balance, 2));
});
