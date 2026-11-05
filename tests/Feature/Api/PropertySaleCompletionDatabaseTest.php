<?php

use App\Models\PropertySale;
use App\Models\PropertySaleCompletionDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('sale completion documents table has required columns', function () {
    expect(
        Schema::hasTable('property_sale_completion_documents')
    )->toBeTrue();

    foreach ([
        'id',
        'property_sale_id',
        'document_type',
        'original_name',
        'disk',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'created_at',
        'updated_at',
    ] as $column) {
        expect(
            Schema::hasColumn(
                'property_sale_completion_documents',
                $column
            )
        )->toBeTrue();
    }
});

test('sale has completion documents relationship', function () {
    $sale = new PropertySale();

    expect($sale->completionDocuments())
        ->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class
        );

    expect((new PropertySaleCompletionDocument())->sale())
        ->toBeInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class
        );
});

test('completion document private storage fields are hidden', function () {
    $document = new PropertySaleCompletionDocument([
        'document_type' => 'signed_agreement',
        'original_name' => 'agreement.pdf',
        'disk' => 'local',
        'file_path' => 'private/agreement.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1000,
    ]);

    expect($document->toArray())
        ->not->toHaveKey('file_path')
        ->not->toHaveKey('disk')
        ->toHaveKey('document_type');
});
