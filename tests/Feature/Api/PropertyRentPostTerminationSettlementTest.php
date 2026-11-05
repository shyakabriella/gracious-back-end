<?php

use App\Services\PropertyRentScheduleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

function postTerminationFixture(): array
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

    Sanctum::actingAs($admin);

    test()->patchJson(
        "/api/rentals/{$rental->id}/terminate",
        [
            'reason' => 'Tenant vacated before lease completion.',
            'move_out_date' => $first->period_end->toDateString(),
        ]
    )->assertOk();

    return [$admin, $agent, $property, $rental, $first];
}

test('rent can be recorded and verified after termination', function () {
    [$admin, $agent, $property, $rental, $first] =
        postTerminationFixture();

    $url = "/api/rentals/{$rental->id}/payments";

    $response = $this->postJson($url, [
        'payment_method' => 'bank_transfer',
        'amount' => (string) $first->amount_due,
        'transaction_reference' => 'POST-TERM-' . bin2hex(random_bytes(5)),
        'payment_date' => now()->toDateString(),
    ])->assertCreated();

    $paymentId = $response->json('payment.id');

    $this->post(
        "{$url}/{$paymentId}/evidences",
        [
            'file' => UploadedFile::fake()->create(
                'receipt.pdf', 40, 'application/pdf'
            ),
        ],
        ['Accept' => 'application/json']
    )->assertCreated();

    $this->patchJson(
        "{$url}/{$paymentId}/verify"
    )
        ->assertOk()
        ->assertJsonPath('payment.status', 'verified');

    $this->getJson(
        "/api/rentals/{$rental->id}/statement"
    )
        ->assertOk()
        ->assertJsonPath(
            'statement.summary.total_outstanding',
            '0.00'
        );

    expect($rental->fresh()->status)->toBe('terminated');
    expect($property->fresh()->availability_status)->toBe('available');
});

test('payment above final adjusted balance is rejected', function () {
    [$admin, $agent, $property, $rental, $first] =
        postTerminationFixture();

    $tooMuch = number_format(
        (float) $first->amount_due + 1,
        2,
        '.',
        ''
    );

    $this->postJson(
        "/api/rentals/{$rental->id}/payments",
        [
            'payment_method' => 'cash',
            'amount' => $tooMuch,
            'payment_date' => now()->toDateString(),
        ]
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

test('draft and cancelled leases cannot receive rent payments', function () {
    if (!function_exists('leaseStatusFixture')) {
        require_once __DIR__ . '/PropertyRentalStatusTest.php';
    }

    foreach (['draft', 'cancelled'] as $status) {
        [$admin, $agent, $property, $rental] =
            leaseStatusFixture($status);

        Sanctum::actingAs($admin);

        $this->postJson(
            "/api/rentals/{$rental->id}/payments",
            [
                'payment_method' => 'cash',
                'amount' => '1000.00',
                'payment_date' => now()->toDateString(),
            ]
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rental');
    }
});

test('terminated lease cannot be reopened by rent payment', function () {
    [$admin, $agent, $property, $rental, $first] =
        postTerminationFixture();

    $this->postJson(
        "/api/rentals/{$rental->id}/payments",
        [
            'payment_method' => 'cash',
            'amount' => '10000.00',
            'payment_date' => now()->toDateString(),
        ]
    )->assertCreated();

    expect($rental->fresh()->status)->toBe('terminated');
});

test('post termination payments do not charge credited future rent', function () {
    [$admin, $agent, $property, $rental, $first] =
        postTerminationFixture();

    $statement = $this->getJson(
        "/api/rentals/{$rental->id}/statement"
    )->assertOk();

    $periods = $statement->json('statement.periods');

    foreach (array_slice($periods, 1) as $period) {
        expect($period['revised_amount'])->toBe('0.00');
    }

    expect($statement->json('statement.summary.total_outstanding'))
        ->toBe((string) $first->amount_due);
});
