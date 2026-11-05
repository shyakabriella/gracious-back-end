<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Property;
use App\Models\PropertyRental;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertyRentalController extends Controller
{
    private function canView(User $user): bool
    {
        return $user->can('rentals.view');
    }

    private function canManage(User $user): bool
    {
        return $user->can('rentals.create')
            && $user->hasAnyRole([
                'super_admin', 'admin', 'property_manager',
            ]);
    }

    private function canUpdate(User $user): bool
    {
        return $user->can('rentals.update')
            && $user->hasAnyRole([
                'super_admin', 'admin', 'property_manager',
            ]);
    }

    private function seesAll(User $user): bool
    {
        return $user->hasAnyRole([
            'super_admin', 'admin',
            'property_manager', 'accountant',
        ]);
    }

    private function visibleRentals(User $user)
    {
        return PropertyRental::query()->when(
            !$this->seesAll($user),
            fn ($query) => $query->where('agent_id', $user->id)
        );
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'property_id' => [
                $required, 'integer', 'exists:properties,id',
            ],
            'customer_id' => [
                $required, 'integer', 'exists:customers,id',
            ],
            'agent_id' => [
                'sometimes', 'nullable', 'integer',
                'exists:users,id',
            ],
            'start_date' => [$required, 'date_format:Y-m-d'],
            'end_date' => [
                $required, 'date_format:Y-m-d',
                'after:start_date',
            ],
            'monthly_rent' => [
                $required, 'numeric', 'gt:0',
                'decimal:0,2',
            ],
            'security_deposit' => [
                'sometimes', 'numeric', 'min:0',
                'decimal:0,2',
            ],
            'payment_due_day' => [
                'sometimes', 'integer', 'between:1,28',
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ];
    }

    private function checkProperty(Property $property): void
    {
        if (
            $property->listing_type !== 'rent'
            || $property->verification_status !== 'verified'
            || $property->availability_status !== 'available'
            || !$property->is_published
        ) {
            throw ValidationException::withMessages([
                'property_id' => [
                    'Property must be verified, published, available and listed for rent.',
                ],
            ]);
        }
    }

    private function checkOverlap(
        int $propertyId,
        string $start,
        string $end,
        ?int $exceptRentalId = null
    ): void {
        $overlaps = PropertyRental::query()
            ->where('property_id', $propertyId)
            ->whereNotIn('status', [
                'cancelled', 'terminated', 'expired',
            ])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->when(
                $exceptRentalId !== null,
                fn ($query) => $query->whereKeyNot($exceptRentalId)
            )
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'start_date' => [
                    'This property already has a rental agreement covering these dates.',
                ],
            ]);
        }
    }

    private function agentFor(Property $property, array $data): ?int
    {
        $agentId = array_key_exists('agent_id', $data)
            ? $data['agent_id']
            : $property->agent_id;

        if ($agentId !== null) {
            $agent = User::findOrFail($agentId);

            if (!$agent->isActive() || !$agent->hasRole('agent')) {
                throw ValidationException::withMessages([
                    'agent_id' => ['Select an active property agent.'],
                ]);
            }
        }

        return $agentId;
    }

    private function detail(PropertyRental $rental): PropertyRental
    {
        return $rental->load([
            'property:id,reference,title,slug',
            'customer:id,reference,name,phone,email',
            'agent:id,name',
            'creator:id,name',
            'statusHistory.changedBy:id,name',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visibleRentals($user)->with([
            'property:id,reference,title',
            'customer:id,reference,name',
            'agent:id,name',
        ]);

        foreach (['status', 'property_id', 'customer_id'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        return response()->json(
            $query->latest('id')->paginate($filters['per_page'] ?? 15)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate($this->rules(true));

        $rental = DB::transaction(function () use ($user, $data) {
            $property = Property::query()
                ->lockForUpdate()
                ->findOrFail($data['property_id']);

            $this->checkProperty($property);

            $customer = Customer::findOrFail($data['customer_id']);

            $this->checkOverlap(
                $property->id,
                $data['start_date'],
                $data['end_date']
            );

            $agentId = $this->agentFor($property, $data);

            $rental = PropertyRental::create([
                'reference' => 'RENT-' . Str::upper(Str::random(16)),
                'property_id' => $property->id,
                'customer_id' => $customer->id,
                'agent_id' => $agentId,
                'created_by' => $user->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'monthly_rent' => $data['monthly_rent'],
                'security_deposit' => $data['security_deposit'] ?? 0,
                'currency' => $property->currency ?: 'RWF',
                'payment_due_day' => $data['payment_due_day'] ?? 1,
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $rental->statusHistory()->create([
                'from_status' => null,
                'to_status' => 'draft',
                'notes' => 'Rental agreement registered.',
                'changed_by' => $user->id,
            ]);

            return $rental;
        });

        return response()->json([
            'message' => 'Draft rental agreement created.',
            'rental' => $this->detail($rental),
        ], 201);
    }

    public function show(
        Request $request,
        PropertyRental $rental
    ): JsonResponse {
        abort_unless($this->canView($request->user()), 403);

        abort_unless(
            $this->visibleRentals($request->user())
                ->whereKey($rental->id)->exists(),
            404
        );

        return response()->json([
            'rental' => $this->detail($rental),
        ]);
    }

    public function update(
        Request $request,
        PropertyRental $rental
    ): JsonResponse {
        abort_unless($this->canUpdate($request->user()), 403);

        $data = $request->validate($this->rules(false));

        $updated = DB::transaction(function () use ($rental, $data) {
            // Lock the property first, matching the order used by store().
            $propertyId = $rental->property_id;

            // Phase 2 does not allow moving an existing lease to
            // a different property. Create a new draft instead.
            if (
                isset($data['property_id'])
                && (int) $data['property_id'] !== (int) $propertyId
            ) {
                throw ValidationException::withMessages([
                    'property_id' => [
                        'Changing the property of a rental is not supported.',
                    ],
                ]);
            }

            $property = Property::query()
                ->lockForUpdate()
                ->findOrFail($propertyId);

            $rental = PropertyRental::query()
                ->lockForUpdate()
                ->findOrFail($rental->id);

            if ($rental->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Only draft rentals may be edited.'],
                ]);
            }

            $this->checkProperty($property);

            $start = $data['start_date']
                ?? $rental->start_date->format('Y-m-d');
            $end = $data['end_date']
                ?? $rental->end_date->format('Y-m-d');

            if ($end <= $start) {
                throw ValidationException::withMessages([
                    'end_date' => [
                        'End date must be after start date.',
                    ],
                ]);
            }

            $this->checkOverlap(
                $propertyId, $start, $end, $rental->id
            );

            if (isset($data['customer_id'])) {
                Customer::findOrFail($data['customer_id']);
            }

            $allowed = array_intersect_key(
                $data,
                array_flip([
                    'customer_id', 'start_date', 'end_date',
                    'monthly_rent', 'security_deposit',
                    'payment_due_day', 'notes',
                ])
            );

            if (array_key_exists('agent_id', $data)) {
                $allowed['agent_id'] = $this->agentFor(
                    $property,
                    ['agent_id' => $data['agent_id']]
                );
            }

            $rental->update($allowed);

            return $rental;
        });

        return response()->json([
            'message' => 'Draft rental updated.',
            'rental' => $this->detail($updated),
        ]);
    }
}
