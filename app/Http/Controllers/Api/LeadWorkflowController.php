<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadWorkflowController extends Controller
{
    private function manager(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function canAccess(User $user, Lead $lead): bool
    {
        return $this->manager($user)
            || $lead->created_by === $user->id
            || $lead->assigned_agent_id === $user->id;
    }

    public function assign(Request $request, Lead $lead): JsonResponse
    {
        abort_unless(
            $this->manager($request->user())
                && $request->user()->can('leads.assign'),
            403
        );

        $data = $request->validate([
            'assigned_agent_id' => [
                'present',
                'nullable',
                'integer',
                'exists:users,id',
            ],
        ]);

        $agent = null;

        if ($data['assigned_agent_id'] !== null) {
            $agent = User::findOrFail($data['assigned_agent_id']);

            if (!$agent->isActive() || !$agent->hasRole('agent')) {
                throw ValidationException::withMessages([
                    'assigned_agent_id' => [
                        'Select an active real estate agent.',
                    ],
                ]);
            }
        }

        $lead = DB::transaction(function () use ($lead, $agent, $request) {
            $lead = Lead::query()
                ->lockForUpdate()
                ->findOrFail($lead->id);

            $previousAgentId = $lead->assigned_agent_id;

            if ($previousAgentId !== $agent?->id) {
                $lead->update([
                    'assigned_agent_id' => $agent?->id,
                ]);

                $lead->activities()->create([
                    'activity_type' => 'assignment',
                    'description' => $agent
                        ? "Lead assigned to {$agent->name}."
                        : 'Lead assignment removed.',
                    'created_by' => $request->user()->id,
                ]);
            }

            return $lead;
        });

        return response()->json([
            'message' => 'Lead assignment updated',
            'lead' => $lead->load('assignedAgent:id,name'),
        ]);
    }

    public function status(Request $request, Lead $lead): JsonResponse
    {
        abort_unless(
            $request->user()->can('leads.update')
                && $this->canAccess($request->user(), $lead),
            403
        );

        $data = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'new',
                    'contacted',
                    'qualified',
                    'viewing',
                    'negotiating',
                    'won',
                    'lost',
                ]),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $lead = DB::transaction(function () use ($lead, $data, $request) {
            $lead = Lead::query()
                ->lockForUpdate()
                ->findOrFail($lead->id);

            abort_unless(
                $this->canAccess($request->user(), $lead),
                403
            );

            $previous = $lead->status;
            $next = $data['status'];

            if ($previous !== $next) {
                $lead->update([
                    'status' => $next,
                    'closed_at' => in_array($next, ['won', 'lost'])
                        ? now()
                        : null,
                ]);

                $description = "Status changed from {$previous} to {$next}.";

                if (!empty($data['notes'])) {
                    $description .= "\n" . $data['notes'];
                }

                $lead->activities()->create([
                    'activity_type' => 'status_change',
                    'description' => $description,
                    'created_by' => $request->user()->id,
                ]);
            }

            return $lead;
        });

        return response()->json([
            'message' => 'Lead status updated',
            'lead' => $lead->fresh(),
        ]);
    }

    public function activities(Request $request, Lead $lead): JsonResponse
    {
        abort_unless(
            $request->user()->can('leads.view')
                && $this->canAccess($request->user(), $lead),
            403
        );

        return response()->json([
            'activities' => $lead->activities()
                ->with('creator:id,name')
                ->orderByDesc('id')
                ->get(),
        ]);
    }
}
