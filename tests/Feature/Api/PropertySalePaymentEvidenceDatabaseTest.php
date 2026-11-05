<?php

use App\Models\PropertySalePayment;
use App\Models\PropertySalePaymentEvidence;
use App\Models\PropertySalePaymentStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('payment evidence and audit tables exist', function () {
    expect(
        Schema::hasTable('property_sale_payment_evidences')
    )->toBeTrue();

    expect(
        Schema::hasTable('property_sale_payment_status_histories')
    )->toBeTrue();

    foreach ([
        'property_sale_payment_id',
        'original_name',
        'disk',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
    ] as $column) {
        expect(
            Schema::hasColumn(
                'property_sale_payment_evidences',
                $column
            )
        )->toBeTrue();
    }

    foreach ([
        'property_sale_payment_id',
        'from_status',
        'to_status',
        'changed_by',
    ] as $column) {
        expect(
            Schema::hasColumn(
                'property_sale_payment_status_histories',
                $column
            )
        )->toBeTrue();
    }
});

test('payment has evidence and status history relationships', function () {
    $payment = new PropertySalePayment();

    expect($payment->evidences())
        ->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class
        );

    expect($payment->statusHistory())
        ->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class
        );

    expect((new PropertySalePaymentEvidence())->payment())
        ->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class
        );

    expect((new PropertySalePaymentStatusHistory())->payment())
        ->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class
        );
});

test('payment evidence storage path is hidden from api serialization', function () {
    $evidence = new PropertySalePaymentEvidence([
        'original_name' => 'bank-receipt.pdf',
        'disk' => 'local',
        'file_path' => 'private/sale-receipts/example.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1200,
    ]);

    expect($evidence->toArray())
        ->not->toHaveKey('file_path')
        ->not->toHaveKey('disk');

    expect($evidence->toArray())
        ->toHaveKey('original_name');
});
