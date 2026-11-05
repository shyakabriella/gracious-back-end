<?php

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('crm database tables exist', function () {
    foreach (['customers', 'leads', 'lead_activities'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

test('one customer can have multiple leads', function () {
    $user = User::factory()->create();

    $customer = Customer::create([
        'reference' => 'CUS-000001',
        'name' => 'Test Customer',
        'phone' => '0788000001',
        'created_by' => $user->id,
    ]);

    foreach (['buy', 'rent'] as $index => $type) {
        Lead::create([
            'reference' => 'LEAD-00000' . ($index + 1),
            'customer_id' => $customer->id,
            'lead_type' => $type,
            'created_by' => $user->id,
        ]);
    }

    expect($customer->leads()->count())->toBe(2);
});

test('lead can have multiple activities', function () {
    $user = User::factory()->create();

    $customer = Customer::create([
        'reference' => 'CUS-000002',
        'name' => 'Another Customer',
        'email' => 'customer@example.com',
    ]);

    $lead = Lead::create([
        'reference' => 'LEAD-000003',
        'customer_id' => $customer->id,
        'lead_type' => 'buy',
    ]);

    foreach (['call', 'note'] as $type) {
        LeadActivity::create([
            'lead_id' => $lead->id,
            'activity_type' => $type,
            'description' => 'Customer follow-up',
            'created_by' => $user->id,
        ]);
    }

    expect($lead->activities()->count())->toBe(2);
});

test('customers and leads support soft deletion', function () {
    $customer = Customer::create([
        'reference' => 'CUS-000004',
        'name' => 'Archived Customer',
    ]);

    $lead = Lead::create([
        'reference' => 'LEAD-000004',
        'customer_id' => $customer->id,
        'lead_type' => 'consultation',
    ]);

    $lead->delete();
    $customer->delete();

    expect(Customer::count())->toBe(0);
    expect(Customer::withTrashed()->count())->toBe(1);
    expect(Lead::count())->toBe(0);
    expect(Lead::withTrashed()->count())->toBe(1);
});
