<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    private function manager(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function visibleCustomers(User $user)
    {
        return Customer::query()
            ->when(!$this->manager($user), fn ($query) =>
                $query->where('created_by', $user->id)
            );
    }

    private function authorizeCustomer(
        Request $request,
        Customer $customer,
        string $permission
    ): void {
        abort_unless($request->user()->can($permission), 403);

        abort_unless(
            $this->manager($request->user())
                || $customer->created_by === $request->user()->id,
            404
        );
    }

    private function rules(bool $creating = true): array
    {
        return [
            'name' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'max:255',
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'customer_type' => [
                'sometimes',
                Rule::in([
                    'buyer', 'seller', 'tenant',
                    'landlord', 'investor',
                ]),
            ],
            'preferred_location' => ['nullable', 'string', 'max:255'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', Rule::in(['RWF', 'USD'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => [
                'sometimes',
                Rule::in(['active', 'inactive']),
            ],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('customers.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'customer_type' => [
                'nullable',
                Rule::in([
                    'buyer', 'seller', 'tenant',
                    'landlord', 'investor',
                ]),
            ],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visibleCustomers($request->user());

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        foreach (['customer_type', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return response()->json(
            $query->latest('id')
                ->paginate($filters['per_page'] ?? 15)
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('customers.create'), 403);

        $data = $request->validate($this->rules());

        if (
            isset($data['budget_min'], $data['budget_max'])
            && $data['budget_max'] < $data['budget_min']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'budget_max' => ['Maximum budget cannot be below minimum budget.'],
            ]);
        }

        $customer = DB::transaction(function () use ($request, $data) {
            $customer = Customer::create([
                ...$data,
                'reference' => 'PENDING-' . Str::uuid(),
                'created_by' => $request->user()->id,
            ]);

            $customer->update([
                'reference' => 'CUS-' . str_pad(
                    (string) $customer->id,
                    6,
                    '0',
                    STR_PAD_LEFT
                ),
            ]);

            return $customer;
        });

        return response()->json([
            'message' => 'Customer registered successfully',
            'customer' => $customer,
        ], 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer, 'customers.view');

        return response()->json([
            'customer' => $customer,
        ]);
    }

    public function update(
        Request $request,
        Customer $customer
    ): JsonResponse {
        $this->authorizeCustomer($request, $customer, 'customers.update');

        $data = $request->validate($this->rules(false));

        $min = array_key_exists('budget_min', $data)
            ? $data['budget_min']
            : $customer->budget_min;

        $max = array_key_exists('budget_max', $data)
            ? $data['budget_max']
            : $customer->budget_max;

        if ($min !== null && $max !== null && $max < $min) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'budget_max' => ['Maximum budget cannot be below minimum budget.'],
            ]);
        }

        $customer->update($data);

        return response()->json([
            'message' => 'Customer updated successfully',
            'customer' => $customer->fresh(),
        ]);
    }
}
