<?php

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyViewing;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function viewingApiUser(string $role): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function viewingApiLead(User $creator, User $agent): Lead
{
    $category = PropertyCategory::firstOrCreate(
        ['slug' => 'viewing-api'],
        ['name' => 'Viewing API', 'is_active' => true]
    );

    $property = Property::create([
        'reference' => 'GI-' . uniqid(),
        'title' => 'Viewing API House',
        'slug' => 'viewing-' . uniqid(),
        'category_id' => $category->id,
        'listing_type' => 'sale',
        'asking_price' => 50000000,
        'created_by' => $creator->id,
    ]);

    $customer = Customer::create([
        'reference' => 'CUS-' . uniqid(),
        'name' => 'Viewing API Customer',
        'created_by' => $creator->id,
    ]);

    return Lead::create([
        'reference' => 'LEAD-' . uniqid(),
        'customer_id' => $customer->id,
        'property_id' => $property->id,
        'lead_type' => 'buy',
        'assigned_agent_id' => $agent->id,
        'created_by' => $creator->id,
    ]);
}

function viewingApiPayload(Lead $lead): array
{
    return [
        'lead_id' => $lead->id,
        'property_id' => $lead->property_id,
        'scheduled_start_at' => now()->addDays(2)->toDateTimeString(),
        'scheduled_end_at' => now()->addDays(2)->addHour()->toDateTimeString(),
    ];
}

test('viewing routes require authentication', function () {
    $this->getJson('/api/viewings')->assertUnauthorized();
    $this->postJson('/api/viewings', [])->assertUnauthorized();
});

test('admin can schedule a viewing', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $lead = viewingApiLead($admin, $agent);

    Sanctum::actingAs($admin);

    $this->postJson('/api/viewings', viewingApiPayload($lead))
        ->assertCreated()
        ->assertJsonPath('viewing.agent_id', $agent->id)
        ->assertJsonPath('viewing.status', 'requested');

    expect(PropertyViewing::count())->toBe(1);
});

test('assigned agent can schedule own viewing', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $lead = viewingApiLead($admin, $agent);

    Sanctum::actingAs($agent);

    $this->postJson('/api/viewings', viewingApiPayload($lead))
        ->assertCreated();
});

test('unrelated agent cannot schedule viewing', function () {
    $admin = viewingApiUser('super_admin');
    $assigned = viewingApiUser('agent');
    $other = viewingApiUser('agent');
    $lead = viewingApiLead($admin, $assigned);

    Sanctum::actingAs($other);

    $this->postJson('/api/viewings', viewingApiPayload($lead))
        ->assertNotFound();
});

test('viewing property must match lead property', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $lead = viewingApiLead($admin, $agent);
    $other = viewingApiLead($admin, $agent);

    Sanctum::actingAs($admin);

    $payload = viewingApiPayload($lead);
    $payload['property_id'] = $other->property_id;

    $this->postJson('/api/viewings', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_id');
});

test('agent cannot have overlapping appointments', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');

    $firstLead = viewingApiLead($admin, $agent);
    $secondLead = viewingApiLead($admin, $agent);

    Sanctum::actingAs($admin);

    $this->postJson('/api/viewings', viewingApiPayload($firstLead))
        ->assertCreated();

    $this->postJson('/api/viewings', viewingApiPayload($secondLead))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_start_at');

    expect(PropertyViewing::count())->toBe(1);
});

test('end time must be later than start time', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');
    $lead = viewingApiLead($admin, $agent);

    Sanctum::actingAs($admin);

    $payload = viewingApiPayload($lead);
    $payload['scheduled_end_at'] = $payload['scheduled_start_at'];

    $this->postJson('/api/viewings', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_end_at');
});

test('agent only lists assigned viewings', function () {
    $admin = viewingApiUser('super_admin');
    $firstAgent = viewingApiUser('agent');
    $secondAgent = viewingApiUser('agent');

    $lead = viewingApiLead($admin, $firstAgent);

    Sanctum::actingAs($admin);

    $this->postJson('/api/viewings', viewingApiPayload($lead))
        ->assertCreated();

    Sanctum::actingAs($secondAgent);

    $this->getJson('/api/viewings')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('customer role cannot schedule internal viewings', function () {
    Sanctum::actingAs(viewingApiUser('customer'));

    $this->postJson('/api/viewings', [])
        ->assertForbidden();
});

test('cancelling a viewing releases the agent time slot', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');

    $firstLead = viewingApiLead($admin, $agent);
    $secondLead = viewingApiLead($admin, $agent);

    Sanctum::actingAs($admin);

    $first = $this->postJson(
        '/api/viewings',
        viewingApiPayload($firstLead)
    )->assertCreated()->json('viewing');

    $this->postJson(
        '/api/viewings',
        viewingApiPayload($secondLead)
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_start_at');

    $this->patchJson("/api/viewings/{$first['id']}/cancel", [
        'reason' => 'Customer requested cancellation.',
    ])->assertOk();

    $this->postJson(
        '/api/viewings',
        viewingApiPayload($secondLead)
    )->assertCreated();

    expect(PropertyViewing::count())->toBe(2);
    expect(
        PropertyViewing::where('status', 'cancelled')->count()
    )->toBe(1);
});

test('confirmed viewing blocks overlapping appointments', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');

    $firstLead = viewingApiLead($admin, $agent);
    $secondLead = viewingApiLead($admin, $agent);

    Sanctum::actingAs($admin);

    $first = $this->postJson(
        '/api/viewings',
        viewingApiPayload($firstLead)
    )->assertCreated()->json('viewing');

    $this->patchJson(
        "/api/viewings/{$first['id']}/confirm"
    )->assertOk();

    $this->postJson(
        '/api/viewings',
        viewingApiPayload($secondLead)
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('scheduled_start_at');
});

test('agent can have consecutive non-overlapping viewings', function () {
    $admin = viewingApiUser('super_admin');
    $agent = viewingApiUser('agent');

    $firstLead = viewingApiLead($admin, $agent);
    $secondLead = viewingApiLead($admin, $agent);

    Sanctum::actingAs($admin);

    $firstPayload = viewingApiPayload($firstLead);

    $this->postJson(
        '/api/viewings',
        $firstPayload
    )->assertCreated();

    $secondPayload = viewingApiPayload($secondLead);

    $secondPayload['scheduled_start_at'] =
        $firstPayload['scheduled_end_at'];

    $secondPayload['scheduled_end_at'] = \Illuminate\Support\Carbon::parse(
        $firstPayload['scheduled_end_at']
    )->addHour()->toDateTimeString();

    $this->postJson(
        '/api/viewings',
        $secondPayload
    )->assertCreated();

    expect(PropertyViewing::count())->toBe(2);
});
