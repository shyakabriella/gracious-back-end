<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyRental;
use App\Models\PropertyRentPayment;
use App\Models\PropertySale;
use App\Models\PropertySalePayment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function dashboardPaymentUser(string $role): User
{
    $user = User::factory()->create([
        'status' => 'active',
    ]);

    $user->assignRole($role);

    return $user;
}

function dashboardPaymentFixture(): array
{
    $admin = dashboardPaymentUser('admin');
    $agent = dashboardPaymentUser('agent');

    $category = PropertyCategory::firstOrCreate(
        ['slug' => 'dashboard-payment-test'],
        [
            'name' => 'Dashboard Payment Test',
            'is_active' => true,
        ]
    );

    $rentalProperty = Property::create([
        'reference' => 'DP-RENT-PROP-' . uniqid(),
        'title' => 'Dashboard Rental Property',
        'slug' => 'dashboard-rental-' . uniqid(),
        'category_id' => $category->id,
        'listing_type' => 'rent',
        'asking_price' => 350000,
        'currency' => 'RWF',
        'agent_id' => $agent->id,
        'created_by' => $admin->id,
    ]);

    $saleProperty = Property::create([
        'reference' => 'DP-SALE-PROP-' . uniqid(),
        'title' => 'Dashboard Sale Property',
        'slug' => 'dashboard-sale-' . uniqid(),
        'category_id' => $category->id,
        'listing_type' => 'sale',
        'asking_price' => 80000000,
        'currency' => 'RWF',
        'agent_id' => $agent->id,
        'created_by' => $admin->id,
    ]);

    $rentalCustomer = Customer::create([
        'reference' => 'DP-CUST-R-' . uniqid(),
        'name' => 'Dashboard Rental Tenant',
        'created_by' => $admin->id,
    ]);

    $saleCustomer = Customer::create([
        'reference' => 'DP-CUST-S-' . uniqid(),
        'name' => 'Dashboard Sale Buyer',
        'created_by' => $admin->id,
    ]);

    $rental = PropertyRental::create([
        'reference' => 'DP-RENT-' . uniqid(),
        'property_id' => $rentalProperty->id,
        'customer_id' => $rentalCustomer->id,
        'agent_id' => $agent->id,
        'created_by' => $admin->id,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => now()->addMonths(11)->toDateString(),
        'monthly_rent' => '350000.00',
        'security_deposit' => '700000.00',
        'currency' => 'RWF',
        'payment_due_day' => 5,
        'status' => 'active',
        'activated_at' => now()->subMonth(),
    ]);

    $sale = PropertySale::create([
        'reference' => 'DP-SALE-' . uniqid(),
        'property_id' => $saleProperty->id,
        'customer_id' => $saleCustomer->id,
        'agent_id' => $agent->id,
        'asking_price' => '80000000.00',
        'agreed_price' => '75000000.00',
        'currency' => 'RWF',
        'status' => 'agreement',
        'agreement_date' => now()->toDateString(),
        'created_by' => $admin->id,
    ]);

    return [$admin, $agent, $rental, $sale];
}

function dashboardRentalPayment(
    PropertyRental $rental,
    User $admin,
    string $reference,
    string $status = 'pending',
    string $amount = '100000.00',
    string $currency = 'RWF'
): PropertyRentPayment {
    return PropertyRentPayment::create([
        'reference' => $reference,
        'property_rental_id' => $rental->id,
        'payment_method' => 'bank_transfer',
        'amount' => $amount,
        'currency' => $currency,
        'transaction_reference' => 'TX-' . $reference,
        'payment_date' => now()->toDateString(),
        'status' => $status,
        'recorded_by' => $admin->id,
        'verified_by' => $status === 'verified'
            ? $admin->id : null,
        'verified_at' => $status === 'verified'
            ? now() : null,
    ]);
}

function dashboardSalePayment(
    PropertySale $sale,
    User $admin,
    string $reference,
    string $status = 'pending',
    string $amount = '200000.00',
    string $currency = 'RWF'
): PropertySalePayment {
    return PropertySalePayment::create([
        'reference' => $reference,
        'property_sale_id' => $sale->id,
        'payment_type' => 'deposit',
        'payment_method' => 'bank_transfer',
        'amount' => $amount,
        'currency' => $currency,
        'transaction_reference' => 'TX-' . $reference,
        'payment_date' => now()->toDateString(),
        'status' => $status,
        'recorded_by' => $admin->id,
        'verified_by' => $status === 'verified'
            ? $admin->id : null,
        'verified_at' => $status === 'verified'
            ? now() : null,
    ]);
}

test('global dashboard requires authentication', function () {
    $this->getJson('/api/dashboard/payments')
        ->assertUnauthorized();
});

test('agents cannot access company-wide payments', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    dashboardRentalPayment(
        $rental, $admin, 'DP-AGENT-001'
    );

    Sanctum::actingAs($agent);

    $this->getJson('/api/dashboard/payments')
        ->assertForbidden();
});

test('admin sees rental and sale payments with customer details', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    dashboardRentalPayment(
        $rental, $admin, 'DP-RENT-001'
    );

    dashboardSalePayment(
        $sale, $admin, 'DP-SALE-001'
    );

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/dashboard/payments')
        ->assertOk()
        ->assertJsonPath('payments.total', 2)
        ->assertJsonPath('access.rentals', true)
        ->assertJsonPath('access.sales', true);

    $rows = collect($response->json('payments.data'));

    $rent = $rows->firstWhere('category', 'rental');
    $salePayment = $rows->firstWhere('category', 'sale');

    expect($rent)->not->toBeNull();
    expect($salePayment)->not->toBeNull();

    expect($rent['customer_name'])
        ->toBe('Dashboard Rental Tenant');

    expect($rent['property_title'])
        ->toBe('Dashboard Rental Property');

    expect($salePayment['customer_name'])
        ->toBe('Dashboard Sale Buyer');

    expect($salePayment['property_title'])
        ->toBe('Dashboard Sale Property');
});

test('property manager sees rentals but cannot access sales', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    dashboardRentalPayment(
        $rental, $admin, 'DP-MANAGER-R'
    );

    dashboardSalePayment(
        $sale, $admin, 'DP-MANAGER-S'
    );

    $manager = dashboardPaymentUser('property_manager');

    Sanctum::actingAs($manager);

    $this->getJson('/api/dashboard/payments')
        ->assertOk()
        ->assertJsonPath('payments.total', 1)
        ->assertJsonPath('payments.data.0.category', 'rental')
        ->assertJsonPath('access.rentals', true)
        ->assertJsonPath('access.sales', false);

    $this->getJson('/api/dashboard/payments?type=sale')
        ->assertForbidden();
});

test('accountant can view rental and sale payments', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    dashboardRentalPayment(
        $rental, $admin, 'DP-ACCOUNTANT-R'
    );

    dashboardSalePayment(
        $sale, $admin, 'DP-ACCOUNTANT-S'
    );

    $accountant = dashboardPaymentUser('accountant');

    Sanctum::actingAs($accountant);

    $this->getJson('/api/dashboard/payments')
        ->assertOk()
        ->assertJsonPath('payments.total', 2)
        ->assertJsonPath('access.rentals', true)
        ->assertJsonPath('access.sales', true);
});

test('status filtering does not distort company summaries', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    dashboardRentalPayment(
        $rental, $admin, 'DP-PENDING', 'pending'
    );

    dashboardRentalPayment(
        $rental, $admin, 'DP-VERIFIED',
        'verified', '50000.00'
    );

    dashboardSalePayment(
        $sale, $admin, 'DP-REJECTED',
        'rejected', '30000.00'
    );

    Sanctum::actingAs($admin);

    $response = $this->getJson(
        '/api/dashboard/payments?status=pending'
    )
        ->assertOk()
        ->assertJsonPath('payments.total', 1)
        ->assertJsonPath(
            'payments.data.0.status',
            'pending'
        );

    $statuses = collect($response->json('summary'))
        ->pluck('status')
        ->all();

    expect($statuses)->toContain('pending');
    expect($statuses)->toContain('verified');
    expect($statuses)->toContain('rejected');
});

test('search can find customers and property titles', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    dashboardRentalPayment(
        $rental, $admin, 'DP-SEARCH-R'
    );

    dashboardSalePayment(
        $sale, $admin, 'DP-SEARCH-S'
    );

    Sanctum::actingAs($admin);

    $this->getJson(
        '/api/dashboard/payments?search=Rental%20Tenant'
    )
        ->assertOk()
        ->assertJsonPath('payments.total', 1)
        ->assertJsonPath(
            'payments.data.0.category',
            'rental'
        );

    $this->getJson(
        '/api/dashboard/payments?search=Sale%20Property'
    )
        ->assertOk()
        ->assertJsonPath('payments.total', 1)
        ->assertJsonPath(
            'payments.data.0.category',
            'sale'
        );
});

test('pagination and filter validation work', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    for ($i = 1; $i <= 3; $i++) {
        dashboardRentalPayment(
            $rental,
            $admin,
            "DP-PAGE-{$i}"
        );
    }

    Sanctum::actingAs($admin);

    $this->getJson(
        '/api/dashboard/payments?per_page=2&page=1'
    )
        ->assertOk()
        ->assertJsonPath('payments.total', 3)
        ->assertJsonCount(2, 'payments.data');

    $this->getJson(
        '/api/dashboard/payments?per_page=2&page=2'
    )
        ->assertOk()
        ->assertJsonCount(1, 'payments.data');

    $this->getJson(
        '/api/dashboard/payments?per_page=100'
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');

    $this->getJson(
        '/api/dashboard/payments?status=unknown'
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('currency totals remain separated', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    dashboardRentalPayment(
        $rental,
        $admin,
        'DP-RWF',
        'verified',
        '100000.00',
        'RWF'
    );

    dashboardSalePayment(
        $sale,
        $admin,
        'DP-USD',
        'verified',
        '50.00',
        'USD'
    );

    Sanctum::actingAs($admin);

    $response = $this->getJson(
        '/api/dashboard/payments'
    )->assertOk();

    $summary = collect($response->json('summary'));

    $rwf = $summary->firstWhere('currency', 'RWF');
    $usd = $summary->firstWhere('currency', 'USD');

    expect($rwf)->not->toBeNull();
    expect($usd)->not->toBeNull();

    expect(number_format(
        (float) $rwf['total_amount'], 2, '.', ''
    ))->toBe('100000.00');

    expect(number_format(
        (float) $usd['total_amount'], 2, '.', ''
    ))->toBe('50.00');
});

test('archived sales are excluded from the dashboard', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    dashboardSalePayment(
        $sale, $admin, 'DP-ARCHIVED'
    );

    $sale->delete();

    Sanctum::actingAs($admin);

    $this->getJson('/api/dashboard/payments')
        ->assertOk()
        ->assertJsonPath('payments.total', 0);
});

test('global payments endpoint does not accept writes', function () {
    [$admin, $agent, $rental, $sale] =
        dashboardPaymentFixture();

    Sanctum::actingAs($admin);

    $this->postJson(
        '/api/dashboard/payments',
        ['amount' => '500000.00']
    )->assertStatus(405);

    expect(PropertyRentPayment::count())->toBe(0);
    expect(PropertySalePayment::count())->toBe(0);
});
