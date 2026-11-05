<?php

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertySale;
use App\Models\PropertySaleStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('property sales database tables exist', function () {
    expect(Schema::hasTable('property_sales'))->toBeTrue();
    expect(Schema::hasTable('property_sale_status_histories'))->toBeTrue();

    foreach ([
        'property_id',
        'customer_id',
        'lead_id',
        'agent_id',
        'asking_price',
        'agreed_price',
        'currency',
        'status',
        'created_by',
        'deleted_at',
    ] as $column) {
        expect(Schema::hasColumn('property_sales', $column))->toBeTrue();
    }
});

test('sale belongs to property customer lead and agent', function () {
    $user = User::factory()->create();

    $category = PropertyCategory::create([
        'name' => 'Sale Test Category',
        'slug' => 'sale-test-category',
        'is_active' => true,
    ]);

    $property = Property::create([
        'reference' => 'PROP-SALE-001',
        'title' => 'Property for Sale',
        'slug' => 'property-for-sale-test',
        'category_id' => $category->id,
        'listing_type' => 'sale',
        'asking_price' => 85000000,
        'created_by' => $user->id,
    ]);

    $customer = Customer::create([
        'reference' => 'CUS-SALE-001',
        'name' => 'Property Buyer',
        'created_by' => $user->id,
    ]);

    $lead = Lead::create([
        'reference' => 'LEAD-SALE-001',
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'lead_type' => 'buy',
        'created_by' => $user->id,
    ]);

    $sale = PropertySale::create([
        'reference' => 'SALE-000001',
        'property_id' => $property->id,
        'customer_id' => $customer->id,
        'lead_id' => $lead->id,
        'agent_id' => $user->id,
        'asking_price' => 85000000,
        'agreed_price' => 82000000,
        'created_by' => $user->id,
    ]);

    expect($sale->property->id)->toBe($property->id);
    expect($sale->customer->id)->toBe($customer->id);
    expect($sale->lead->id)->toBe($lead->id);
    expect($sale->agent->id)->toBe($user->id);
    expect($sale->status)->toBe('negotiation');
    expect($sale->currency)->toBe('RWF');
    expect($sale->asking_price)->toBe('85000000.00');
    expect($sale->agreed_price)->toBe('82000000.00');

    $history = PropertySaleStatusHistory::create([
        'property_sale_id' => $sale->id,
        'from_status' => null,
        'to_status' => 'negotiation',
        'changed_by' => $user->id,
    ]);

    expect($sale->statusHistory()->count())->toBe(1);
    expect($history->sale->id)->toBe($sale->id);

    $sale->delete();

    expect(PropertySale::count())->toBe(0);
    expect(PropertySale::withTrashed()->count())->toBe(1);
});
