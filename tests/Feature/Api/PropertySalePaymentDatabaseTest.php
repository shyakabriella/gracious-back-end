<?php

use App\Models\PropertySale;
use App\Models\PropertySalePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('property sale payments table exists with required columns', function () {
    expect(Schema::hasTable('property_sale_payments'))->toBeTrue();

    foreach ([
        'id',
        'reference',
        'property_sale_id',
        'payment_type',
        'payment_method',
        'amount',
        'currency',
        'transaction_reference',
        'payment_date',
        'status',
        'recorded_by',
        'verified_by',
        'verified_at',
        'created_at',
        'updated_at',
    ] as $column) {
        expect(
            Schema::hasColumn('property_sale_payments', $column)
        )->toBeTrue();
    }
});

test('sale has payments relationship and payment defaults to pending', function () {
    $sale = new PropertySale();
    $payment = new PropertySalePayment();

    expect($sale->payments())
        ->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class
        );

    expect($payment->sale())
        ->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class
        );

    expect($payment->status)->toBe('pending');
});
