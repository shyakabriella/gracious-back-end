<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadFollowUpController extends Controller
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

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('leads.view'), 403);

        $filters = $request->validate([
            'filter' => ['nullable', Rule::in([
                'all', 'upcoming', 'overdue',
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        $query = LeadActivity::query()
            ->where('activity_type', 'follow_up')
            ->whereNull('completed_at')
            ->whereNotNull('scheduled_at')
            ->whereHas('lead', function ($query) use ($user) {
                if (!$this->manager($user)) {
                    $query->where(function ($scope) use ($user) {
                        $scope->where('created_by', $user->id)
                            ->orWhere('assigned_agent_id', $user->id);
                    });
                }
            })
            ->with([
                'lead:id,reference,customer_id,assigned_agent_id,status',
                'lead.customer:id,name,phone',
                'creator:id,name',
            ]);

        if (($filters['filter'] ?? 'all') === 'upcoming') {
            $query->where('scheduled_at', '>', now());
        }

        if (($filters['filter'] ?? 'all') === 'overdue') {
            $query->where('scheduled_at', '<=', now());
        }

        return response()->json(
            $query->orderBy('scheduled_at')
                ->orderBy('id')
                ->paginate($filters['per_page'] ?? 15)
        );
    }

    public function complete(
        Request $request,
        Lead $lead,
        LeadActivity $activity
    ): JsonResponse {
        abort_unless($request->user()->can('leads.update'), 403);
        abort_unless($this->canAccess($request->user(), $lead), 404);

        abort_unless($activity->lead_id === $lead->id, 404);
        abort_unless($activity->activity_type === 'follow_up', 404);

        $result = DB::transaction(function () use ($request, $lead, $activity) {
            $lead = Lead::query()
                ->lockForUpdate()
                ->findOrFail($lead->id);

            abort_unless(
                $this->canAccess($request->user(), $lead),
                404
            );

            $activity = LeadActivity::query()
                ->where('lead_id', $lead->id)
                ->where('activity_type', 'follow_up')
                ->lockForUpdate()
                ->findOrFail($activity->id);

            if ($activity->completed_at === null) {
                $activity->update([
                    'completed_at' => now(),
                ]);
            }

            $next = $lead->activities()
                ->where('activity_type', 'follow_up')
                ->whereNull('completed_at')
                ->whereNotNull('scheduled_at')
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->first();

            $lead->update([
                'next_follow_up_at' => $next?->scheduled_at,
            ]);

            return [$lead, $activity];
        });

        [$lead, $activity] = $result;

        return response()->json([
            'message' => 'Follow-up completed successfully',
            'activity' => $activity->fresh(),
            'next_follow_up_at' => $lead->fresh()->next_follow_up_at,
        ]);
    }
}
