<?php

use App\Models\PropertyRental;
use App\Models\PropertyRentalStatusHistory;
use App\Models\PropertyRentSchedule;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('rental foundation tables exist', function () {
    expect(Schema::hasTable('property_rentals'))->toBeTrue();
    expect(Schema::hasTable('property_rental_status_histories'))->toBeTrue();
    expect(Schema::hasTable('property_rent_schedules'))->toBeTrue();
});

test('rental contains required agreement fields', function () {
    foreach ([
        'reference',
        'property_id',
        'customer_id',
        'agent_id',
        'created_by',
        'start_date',
        'end_date',
        'monthly_rent',
        'security_deposit',
        'currency',
        'status',
        'payment_due_day',
    ] as $column) {
        expect(
            Schema::hasColumn('property_rentals', $column)
        )->toBeTrue();
    }
});

test('rental models have correct relationships', function () {
    $rental = new PropertyRental();

    expect($rental->property())->toBeInstanceOf(BelongsTo::class);
    expect($rental->customer())->toBeInstanceOf(BelongsTo::class);
    expect($rental->agent())->toBeInstanceOf(BelongsTo::class);
    expect($rental->creator())->toBeInstanceOf(BelongsTo::class);
    expect($rental->statusHistory())->toBeInstanceOf(HasMany::class);
    expect($rental->rentSchedules())->toBeInstanceOf(HasMany::class);

    expect((new PropertyRentalStatusHistory())->rental())
        ->toBeInstanceOf(BelongsTo::class);

    expect((new PropertyRentSchedule())->rental())
        ->toBeInstanceOf(BelongsTo::class);
});
