<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    private function manager(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function visibleLeads(User $user)
    {
        return Lead::query()
            ->when(!$this->manager($user), function ($query) use ($user) {
                $query->where(function ($scope) use ($user) {
                    $scope->where('created_by', $user->id)
                        ->orWhere('assigned_agent_id', $user->id);
                });
            });
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('leads.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([
                'new', 'contacted', 'qualified', 'viewing',
                'negotiating', 'won', 'lost',
            ])],
            'lead_type' => ['nullable', Rule::in([
                'buy', 'sell', 'rent', 'let', 'consultation',
            ])],
            'priority' => ['nullable', Rule::in([
                'low', 'medium', 'high',
            ])],
            'assigned_agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visibleLeads($request->user())
            ->with([
                'customer:id,reference,name,phone,email',
                'property:id,reference,title,slug',
                'assignedAgent:id,name',
            ]);

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($scope) use ($search) {
                $scope->where('reference', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customer) use ($search) {
                        $customer->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        foreach ([
            'status',
            'lead_type',
            'priority',
            'assigned_agent_id',
        ] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 15)
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('leads.create'), 403);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'lead_type' => ['required', Rule::in([
                'buy', 'sell', 'rent', 'let', 'consultation',
            ])],
            'source' => ['sometimes', Rule::in([
                'phone', 'walk_in', 'referral', 'social_media', 'staff',
            ])],
            'priority' => ['sometimes', Rule::in([
                'low', 'medium', 'high',
            ])],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $user = $request->user();

        $customer = Customer::findOrFail($data['customer_id']);

        if (!$this->manager($user) &&
            $customer->created_by !== $user->id) {
            throw ValidationException::withMessages([
                'customer_id' => ['Selected customer is not available.'],
            ]);
        }

        if (isset($data['property_id'])) {
            $property = Property::findOrFail($data['property_id']);

            if (!$this->manager($user) &&
                $property->created_by !== $user->id &&
                $property->agent_id !== $user->id) {
                throw ValidationException::withMessages([
                    'property_id' => ['Selected property is not available.'],
                ]);
            }
        }

        $lead = DB::transaction(function () use ($data, $user) {
            $lead = Lead::create([
                ...$data,
                'reference' => 'PENDING-' . Str::uuid(),
                'created_by' => $user->id,
                'status' => 'new',
                'assigned_agent_id' => $user->hasRole('agent')
                    ? $user->id
                    : null,
            ]);

            $lead->update([
                'reference' => 'LEAD-' . str_pad(
                    (string) $lead->id,
                    6,
                    '0',
                    STR_PAD_LEFT
                ),
            ]);

            return $lead;
        });

        return response()->json([
            'message' => 'Lead registered successfully',
            'lead' => $lead->load([
                'customer:id,reference,name,phone,email',
                'property:id,reference,title,slug',
                'assignedAgent:id,name',
            ]),
        ], 201);
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        abort_unless($request->user()->can('leads.view'), 403);

        abort_unless(
            $this->manager($request->user())
                || $lead->created_by === $request->user()->id
                || $lead->assigned_agent_id === $request->user()->id,
            404
        );

        return response()->json([
            'lead' => $lead->load([
                'customer',
                'property:id,reference,title,slug',
                'assignedAgent:id,name',
                'creator:id,name',
            ]),
        ]);
    }
}
