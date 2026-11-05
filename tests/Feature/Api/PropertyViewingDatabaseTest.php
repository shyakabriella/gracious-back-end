<?php

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyViewing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('property viewings table has required columns', function () {
    expect(Schema::hasTable('property_viewings'))->toBeTrue();

    foreach ([
        'lead_id',
        'property_id',
        'agent_id',
        'scheduled_start_at',
        'scheduled_end_at',
        'status',
        'created_by',
        'deleted_at',
    ] as $column) {
        expect(
            Schema::hasColumn('property_viewings', $column)
        )->toBeTrue();
    }
});

test('property viewing belongs to lead property and agent', function () {
    $user = User::factory()->create();

    $category = PropertyCategory::create([
        'name' => 'Viewing House',
        'slug' => 'viewing-house',
        'is_active' => true,
    ]);

    $property = Property::create([
        'reference' => 'GI-VIEW-001',
        'title' => 'Viewing Test House',
        'slug' => 'viewing-test-house',
        'category_id' => $category->id,
        'listing_type' => 'sale',
        'asking_price' => 50000000,
        'created_by' => $user->id,
    ]);

    $customer = Customer::create([
        'reference' => 'CUS-VIEW-001',
        'name' => 'Viewing Customer',
        'created_by' => $user->id,
    ]);

    $lead = Lead::create([
        'reference' => 'LEAD-VIEW-001',
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'lead_type' => 'buy',
        'created_by' => $user->id,
    ]);

    $viewing = PropertyViewing::create([
        'reference' => 'VIEW-000001',
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'agent_id' => $user->id,
        'scheduled_start_at' => now()->addDays(2),
        'scheduled_end_at' => now()->addDays(2)->addHour(),
        'created_by' => $user->id,
    ]);

    expect($viewing->lead->id)->toBe($lead->id);
    expect($viewing->property->id)->toBe($property->id);
    expect($viewing->agent->id)->toBe($user->id);
    expect($viewing->status)->toBe('requested');

    $viewing->delete();

    expect(PropertyViewing::count())->toBe(0);
    expect(PropertyViewing::withTrashed()->count())->toBe(1);
});
