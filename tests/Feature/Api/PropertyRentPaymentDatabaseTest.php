<?php

use App\Models\PropertyRental;
use App\Models\PropertyRentSchedule;
use App\Models\PropertyRentPayment;
use App\Models\PropertyRentPaymentAllocation;
use App\Models\PropertyRentPaymentEvidence;
use App\Models\PropertyRentPaymentStatusHistory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('rental payment foundation tables exist', function () {
    foreach ([
        'property_rent_payments',
        'property_rent_payment_allocations',
        'property_rent_payment_evidences',
        'property_rent_payment_status_histories',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

test('rental payment tables contain essential financial fields', function () {
    foreach ([
        'property_rental_id',
        'amount',
        'currency',
        'status',
        'recorded_by',
        'verified_by',
        'verified_at',
    ] as $column) {
        expect(
            Schema::hasColumn('property_rent_payments', $column)
        )->toBeTrue();
    }

    foreach ([
        'property_rent_payment_id',
        'property_rent_schedule_id',
        'amount',
    ] as $column) {
        expect(
            Schema::hasColumn(
                'property_rent_payment_allocations',
                $column
            )
        )->toBeTrue();
    }
});

test('rental payment model relationships are connected', function () {
    $payment = new PropertyRentPayment();

    expect($payment->rental())->toBeInstanceOf(BelongsTo::class);
    expect($payment->allocations())->toBeInstanceOf(HasMany::class);
    expect($payment->evidences())->toBeInstanceOf(HasMany::class);
    expect($payment->statusHistory())->toBeInstanceOf(HasMany::class);
    expect($payment->recorder())->toBeInstanceOf(BelongsTo::class);
    expect($payment->verifier())->toBeInstanceOf(BelongsTo::class);

    expect((new PropertyRental())->payments())
        ->toBeInstanceOf(HasMany::class);

    expect((new PropertyRentSchedule())->allocations())
        ->toBeInstanceOf(HasMany::class);

    expect((new PropertyRentPaymentAllocation())->schedule())
        ->toBeInstanceOf(BelongsTo::class);

    expect((new PropertyRentPaymentEvidence())->payment())
        ->toBeInstanceOf(BelongsTo::class);

    expect((new PropertyRentPaymentStatusHistory())->changedBy())
        ->toBeInstanceOf(BelongsTo::class);
});

test('private rental payment receipt paths are hidden', function () {
    $evidence = new PropertyRentPaymentEvidence([
        'original_name' => 'receipt.pdf',
        'disk' => 'local',
        'file_path' => 'private/receipt.pdf',
    ]);

    $serialized = $evidence->toArray();

    expect($serialized)->not->toHaveKey('disk');
    expect($serialized)->not->toHaveKey('file_path');
});
