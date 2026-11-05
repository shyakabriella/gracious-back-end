<?php

use App\Models\User;
use App\Services\PropertyRentOverpaymentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function refundApprovalFixture(): array
{
    if (!function_exists('refundApiFixture')) {
        require_once __DIR__ . '/PropertyRentRefundApiTest.php';
    }

    [$admin, $agent, $rental, $payment] = refundApiFixture();

    // Create a separate administrator for approval.
    $reviewer = User::factory()->create([
        'status' => 'active',
    ]);

    $reviewer->assignRole('admin');

    Sanctum::actingAs($admin);

    $response = test()->postJson(
        "/api/rentals/{$rental->id}/refunds",
        [
            'property_rent_payment_id' => $payment->id,
            'amount' => '10000.00',
            'reason' => 'Return unused prepaid rent.',
        ]
    )->assertCreated();

    return [
        $admin,
        $reviewer,
        $agent,
        $rental,
        $payment,
        $response->json('refund.id'),
    ];
}

test('refund requester cannot approve their own request', function () {
    [$admin, $reviewer, $agent, $rental, $payment, $refundId] =
        refundApprovalFixture();

    $this->patchJson(
        "/api/rentals/{$rental->id}/refunds/{$refundId}/approve"
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('approval');
});

test('separate administrator can approve refund', function () {
    [$admin, $reviewer, $agent, $rental, $payment, $refundId] =
        refundApprovalFixture();

    Sanctum::actingAs($reviewer);

    $this->patchJson(
        "/api/rentals/{$rental->id}/refunds/{$refundId}/approve"
    )
        ->assertOk()
        ->assertJsonPath('refund.status', 'approved')
        ->assertJsonPath('refund.approved_by', $reviewer->id);

    $this->assertDatabaseHas(
        'property_rent_refund_status_histories',
        [
            'property_rent_refund_id' => $refundId,
            'from_status' => 'pending',
            'to_status' => 'approved',
            'changed_by' => $reviewer->id,
        ]
    );

    $balance = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($balance['reserved_refunds'])->toBe('10000.00');
});

test('approved refund cannot be approved twice', function () {
    [$admin, $reviewer, $agent, $rental, $payment, $refundId] =
        refundApprovalFixture();

    Sanctum::actingAs($reviewer);

    $url = "/api/rentals/{$rental->id}/refunds/{$refundId}/approve";

    $this->patchJson($url)->assertOk();

    $this->patchJson($url)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('rejected refund releases reserved balance', function () {
    [$admin, $reviewer, $agent, $rental, $payment, $refundId] =
        refundApprovalFixture();

    $before = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    Sanctum::actingAs($reviewer);

    $this->patchJson(
        "/api/rentals/{$rental->id}/refunds/{$refundId}/reject",
        ['reason' => 'Refund evidence is insufficient.']
    )
        ->assertOk()
        ->assertJsonPath('refund.status', 'rejected');

    $after = app(PropertyRentOverpaymentService::class)
        ->calculate($rental);

    expect($before['reserved_refunds'])->toBe('10000.00')
        ->and($after['reserved_refunds'])->toBe('0.00')
        ->and($after['available_refund'])
        ->toBe($after['overpayment']);

    $this->assertDatabaseHas(
        'property_rent_refund_status_histories',
        [
            'property_rent_refund_id' => $refundId,
            'to_status' => 'rejected',
            'changed_by' => $reviewer->id,
        ]
    );
});

test('agent cannot approve or reject refunds', function () {
    [$admin, $reviewer, $agent, $rental, $payment, $refundId] =
        refundApprovalFixture();

    Sanctum::actingAs($agent);

    $base = "/api/rentals/{$rental->id}/refunds/{$refundId}";

    $this->patchJson("{$base}/approve")->assertForbidden();

    $this->patchJson(
        "{$base}/reject",
        ['reason' => 'Unauthorized refund decision.']
    )->assertForbidden();
});

test('refund cannot be approved through another rental', function () {
    [$admin, $reviewer, $agent, $rental, $payment, $refundId] =
        refundApprovalFixture();

    if (!function_exists('leaseStatusFixture')) {
        require_once __DIR__ . '/PropertyRentalStatusTest.php';
    }

    [$otherAdmin, $otherAgent, $otherProperty, $otherRental] =
        leaseStatusFixture('active');

    Sanctum::actingAs($reviewer);

    $this->patchJson(
        "/api/rentals/{$otherRental->id}/refunds/{$refundId}/approve"
    )->assertNotFound();
});
