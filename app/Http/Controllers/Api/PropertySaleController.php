<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertySale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertySaleController extends Controller
{
    private function isAdmin(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function canAccess(User $user): bool
    {
        return $this->isAdmin($user) || $user->hasRole('agent');
    }

    private function visibleSales(User $user)
    {
        return PropertySale::query()
            ->when(
                !$this->isAdmin($user),
                fn ($query) => $query->where('agent_id', $user->id)
            );
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($this->canAccess($user), 403);

        $filters = $request->validate([
            'status' => [
                'nullable',
                'in:negotiation,agreement,pending_completion,completed,cancelled',
            ],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visibleSales($user)->with([
            'property:id,reference,title,slug',
            'customer:id,reference,name,phone',
            'agent:id,name',
        ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['property_id'])) {
            $query->where('property_id', $filters['property_id']);
        }

        if ($this->isAdmin($user) && !empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        return response()->json(
            $query->latest('id')->paginate($filters['per_page'] ?? 15)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($this->canAccess($user), 403);

        $data = $request->validate([
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'agreed_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'expected_completion_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $sale = DB::transaction(function () use ($data, $user) {
            $property = Property::query()
                ->lockForUpdate()
                ->findOrFail($data['property_id']);

            if (
                $property->listing_type !== 'sale'
                || $property->verification_status !== 'verified'
                || $property->availability_status !== 'available'
                || !$property->is_published
            ) {
                throw ValidationException::withMessages([
                    'property_id' => [
                        'This property is not eligible for a new sale.',
                    ],
                ]);
            }

            $customer = Customer::findOrFail($data['customer_id']);

            $lead = !empty($data['lead_id'])
                ? Lead::findOrFail($data['lead_id'])
                : null;

            if ($lead && (
                (int) $lead->property_id !== $property->id
                || (int) $lead->customer_id !== $customer->id
                || $lead->lead_type !== 'buy'
            )) {
                throw ValidationException::withMessages([
                    'lead_id' => [
                        'The selected lead must match the property and buyer.',
                    ],
                ]);
            }

            if ($lead && in_array($lead->status, ['won', 'lost'], true)) {
                throw ValidationException::withMessages([
                    'lead_id' => ['A closed lead cannot start a new sale.'],
                ]);
            }

            $agentId = $this->isAdmin($user)
                ? ($data['agent_id'] ?? $lead?->assigned_agent_id ?? $property->agent_id)
                : $user->id;

            $agent = $agentId ? User::find($agentId) : null;

            if (!$agent || !$agent->isActive() || !$agent->hasRole('agent')) {
                throw ValidationException::withMessages([
                    'agent_id' => ['Select an active sales agent.'],
                ]);
            }

            if (
                $lead
                && $lead->assigned_agent_id
                && (int) $lead->assigned_agent_id !== $agent->id
            ) {
                throw ValidationException::withMessages([
                    'agent_id' => [
                        'The sale agent must match the assigned lead agent.',
                    ],
                ]);
            }

            if (!$this->isAdmin($user)) {
                if ($lead) {
                    abort_unless(
                        (int) $lead->assigned_agent_id === $user->id,
                        404
                    );
                } else {
                    abort_unless(
                        (int) $property->agent_id === $user->id,
                        404
                    );
                }

                abort_unless(
                    (int) $customer->created_by === $user->id
                    || ($lead && (int) $lead->assigned_agent_id === $user->id),
                    404
                );
            }

            $alreadyActive = PropertySale::query()
                ->where('property_id', $property->id)
                ->whereIn('status', [
                    'negotiation', 'agreement', 'pending_completion',
                ])
                ->exists();

            if ($alreadyActive) {
                throw ValidationException::withMessages([
                    'property_id' => [
                        'This property already has an active sale transaction.',
                    ],
                ]);
            }

            $sale = PropertySale::create([
                'reference' => 'SALE-' . Str::upper(Str::random(16)),
                'property_id' => $property->id,
                'customer_id' => $customer->id,
                'lead_id' => $lead?->id,
                'agent_id' => $agent->id,
                'asking_price' => $property->asking_price,
                'agreed_price' => $data['agreed_price'],
                'currency' => $property->currency ?: 'RWF',
                'status' => 'negotiation',
                'expected_completion_date' =>
                    $data['expected_completion_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $sale->statusHistory()->create([
                'from_status' => null,
                'to_status' => 'negotiation',
                'notes' => 'Sale negotiation registered.',
                'changed_by' => $user->id,
            ]);

            return $sale;
        });

        return response()->json([
            'message' => 'Sale negotiation created successfully.',
            'sale' => $sale->load([
                'property:id,reference,title',
                'customer:id,reference,name',
                'agent:id,name',
            ]),
        ], 201);
    }

    public function show(Request $request, PropertySale $sale): JsonResponse
    {
        $user = $request->user();

        abort_unless($this->canAccess($user), 403);

        abort_unless(
            $this->isAdmin($user) || (int) $sale->agent_id === $user->id,
            404
        );

        return response()->json([
            'sale' => $sale->load([
                'property:id,reference,title,slug',
                'customer:id,reference,name,phone,email',
                'lead:id,reference,status',
                'agent:id,name',
                'creator:id,name',
                'statusHistory.changedBy:id,name',
            ]),
        ]);
    }
}
