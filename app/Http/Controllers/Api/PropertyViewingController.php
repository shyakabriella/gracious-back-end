<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\PropertyViewing;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PropertyViewingController extends Controller
{
    private function manager(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function allowed(User $user): bool
    {
        return $this->manager($user) || $user->hasRole('agent');
    }

    private function visible(User $user)
    {
        return PropertyViewing::query()
            ->when(!$this->manager($user), fn ($query) =>
                $query->where('agent_id', $user->id)
            );
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->allowed($request->user()), 403);

        $filters = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    'requested', 'confirmed', 'completed',
                    'cancelled', 'no_show',
                ]),
            ],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visible($request->user())
            ->with([
                'lead:id,reference,customer_id',
                'lead.customer:id,name,phone',
                'property:id,reference,title,slug',
                'agent:id,name',
            ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (
            $this->manager($request->user())
            && !empty($filters['agent_id'])
        ) {
            $query->where('agent_id', $filters['agent_id']);
        }

        return response()->json(
            $query->orderBy('scheduled_start_at')
                ->orderBy('id')
                ->paginate($filters['per_page'] ?? 15)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($this->allowed($user), 403);

        $data = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'scheduled_start_at' => ['required', 'date', 'after:now'],
            'scheduled_end_at' => [
                'required', 'date', 'after:scheduled_start_at',
            ],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $start = Carbon::parse($data['scheduled_start_at']);
        $end = Carbon::parse($data['scheduled_end_at']);

        if ($start->diffInHours($end) > 4) {
            throw ValidationException::withMessages([
                'scheduled_end_at' => [
                    'A viewing cannot last more than four hours.',
                ],
            ]);
        }

        $viewing = DB::transaction(function () use ($data, $user, $start, $end) {
            $lead = Lead::query()
                ->lockForUpdate()
                ->findOrFail($data['lead_id']);

            if (
                (int) $lead->property_id !== (int) $data['property_id']
            ) {
                throw ValidationException::withMessages([
                    'property_id' => [
                        'The selected property does not match this lead.',
                    ],
                ]);
            }

            if (in_array($lead->status, ['won', 'lost'], true)) {
                throw ValidationException::withMessages([
                    'lead_id' => ['A closed lead cannot be scheduled.'],
                ]);
            }

            $agent = $lead->assigned_agent_id
                ? User::query()
                    ->lockForUpdate()
                    ->find($lead->assigned_agent_id)
                : null;

            if (
                !$agent
                || !$agent->isActive()
                || !$agent->hasRole('agent')
            ) {
                throw ValidationException::withMessages([
                    'lead_id' => [
                        'Assign this lead to an active agent first.',
                    ],
                ]);
            }

            if (!$this->manager($user) && $agent->id !== $user->id) {
                abort(404);
            }

            $overlap = PropertyViewing::query()
                ->where('agent_id', $agent->id)
                ->whereIn('status', ['requested', 'confirmed'])
                ->where('scheduled_start_at', '<', $end)
                ->where('scheduled_end_at', '>', $start)
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages([
                    'scheduled_start_at' => [
                        'The agent already has a viewing during this period.',
                    ],
                ]);
            }

            $viewing = PropertyViewing::create([
                'reference' => 'PENDING-' . (string) \Illuminate\Support\Str::uuid(),
                'lead_id' => $lead->id,
                'property_id' => $lead->property_id,
                'agent_id' => $agent->id,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $viewing->update([
                'reference' => 'VIEW-' . str_pad(
                    (string) $viewing->id, 6, '0', STR_PAD_LEFT
                ),
            ]);

            return $viewing;
        });

        return response()->json([
            'message' => 'Property viewing scheduled successfully',
            'viewing' => $viewing->load([
                'lead:id,reference,customer_id',
                'property:id,reference,title',
                'agent:id,name',
            ]),
        ], 201);
    }

    public function show(
        Request $request,
        PropertyViewing $viewing
    ): JsonResponse {
        abort_unless($this->allowed($request->user()), 403);

        abort_unless(
            $this->manager($request->user())
                || $viewing->agent_id === $request->user()->id,
            404
        );

        return response()->json([
            'viewing' => $viewing->load([
                'lead.customer:id,name,phone,email',
                'property:id,reference,title,slug',
                'agent:id,name',
                'creator:id,name',
            ]),
        ]);
    }
}
