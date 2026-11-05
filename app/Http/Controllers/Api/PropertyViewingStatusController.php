<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyViewing;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PropertyViewingStatusController extends Controller
{
    private function canManage(User $user, PropertyViewing $viewing): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin'])
            || (
                $user->hasRole('agent')
                && $viewing->agent_id === $user->id
            );
    }

    public function confirm(Request $request, PropertyViewing $viewing): JsonResponse
    {
        return $this->change($request, $viewing, 'confirmed');
    }

    public function cancel(Request $request, PropertyViewing $viewing): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->change($request, $viewing, 'cancelled');
    }

    public function complete(Request $request, PropertyViewing $viewing): JsonResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return $this->change($request, $viewing, 'completed');
    }

    public function noShow(Request $request, PropertyViewing $viewing): JsonResponse
    {
        return $this->change($request, $viewing, 'no_show');
    }

    private function change(
        Request $request,
        PropertyViewing $viewing,
        string $next
    ): JsonResponse {
        $user = $request->user();

        abort_unless($this->canManage($user, $viewing), 404);

        $viewing = DB::transaction(function () use ($viewing, $user, $request, $next) {
            $viewing = PropertyViewing::query()
                ->lockForUpdate()
                ->findOrFail($viewing->id);

            abort_unless($this->canManage($user, $viewing), 404);

            $allowed = [
                'requested' => ['confirmed', 'cancelled'],
                'confirmed' => ['completed', 'cancelled', 'no_show'],
            ];

            if (!in_array($next, $allowed[$viewing->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ['This appointment status change is not allowed.'],
                ]);
            }

            if (
                in_array($next, ['completed', 'no_show'], true)
                && now()->lt($viewing->scheduled_start_at)
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'The scheduled viewing time has not started yet.',
                    ],
                ]);
            }

            $previous = $viewing->status;

            $changes = ['status' => $next];

            if ($next === 'cancelled') {
                $changes['cancellation_reason'] = $request->input('reason');
            }

            if ($next === 'completed') {
                $changes['completed_at'] = now();

                if ($request->filled('notes')) {
                    $changes['notes'] = $request->input('notes');
                }
            }

            $viewing->update($changes);

            DB::table('property_viewing_status_histories')->insert([
                'property_viewing_id' => $viewing->id,
                'from_status' => $previous,
                'to_status' => $next,
                'notes' => $next === 'cancelled'
                    ? $request->input('reason')
                    : ($request->input('notes') ?: null),
                'changed_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $viewing;
        });

        return response()->json([
            'message' => 'Viewing status updated successfully.',
            'viewing' => $viewing->fresh(),
        ]);
    }

    public function history(Request $request, PropertyViewing $viewing): JsonResponse
    {
        abort_unless($this->canManage($request->user(), $viewing), 404);

        $history = DB::table('property_viewing_status_histories')
            ->join(
                'users',
                'users.id',
                '=',
                'property_viewing_status_histories.changed_by'
            )
            ->where('property_viewing_id', $viewing->id)
            ->select(
                'property_viewing_status_histories.id',
                'from_status',
                'to_status',
                'notes',
                'changed_by',
                'users.name as changed_by_name',
                'property_viewing_status_histories.created_at'
            )
            ->orderBy('property_viewing_status_histories.id')
            ->get();

        return response()->json(['history' => $history]);
    }
}
