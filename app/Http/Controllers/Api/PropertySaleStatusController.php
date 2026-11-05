<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertySale;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PropertySaleStatusController extends Controller
{
    private function isAdmin(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function canView(User $user, PropertySale $sale): bool
    {
        return $this->isAdmin($user)
            || ($user->hasRole('agent') && (int) $sale->agent_id === $user->id);
    }

    public function agreement(Request $request, PropertySale $sale): JsonResponse
    {
        abort_unless($this->isAdmin($request->user()), 403);

        $data = $request->validate([
            'agreement_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return $this->change($request, $sale, 'agreement', $data);
    }

    public function pendingCompletion(
        Request $request,
        PropertySale $sale
    ): JsonResponse {
        abort_unless($this->isAdmin($request->user()), 403);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return $this->change($request, $sale, 'pending_completion', $data);
    }

    public function cancel(Request $request, PropertySale $sale): JsonResponse
    {
        abort_unless($this->canView($request->user(), $sale), 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->change($request, $sale, 'cancelled', $data);
    }

    private function change(
        Request $request,
        PropertySale $sale,
        string $next,
        array $data
    ): JsonResponse {
        $user = $request->user();

        $updated = DB::transaction(function () use ($sale, $user, $next, $data) {
            $sale = PropertySale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            abort_unless($this->canView($user, $sale), 404);

            $allowed = [
                'negotiation' => ['agreement', 'cancelled'],
                'agreement' => ['pending_completion', 'cancelled'],
                'pending_completion' => ['cancelled'],
            ];

            if (!in_array($next, $allowed[$sale->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ['This sale status change is not allowed.'],
                ]);
            }

            if (
                !$this->isAdmin($user)
                && !($sale->status === 'negotiation' && $next === 'cancelled')
            ) {
                abort(403);
            }

            if ($next !== 'cancelled') {
                $property = Property::query()
                    ->findOrFail($sale->property_id);

                if (
                    $property->listing_type !== 'sale'
                    || $property->availability_status !== 'available'
                ) {
                    throw ValidationException::withMessages([
                        'property_id' => [
                            'The property is no longer eligible for this sale.',
                        ],
                    ]);
                }
            }

            $previous = $sale->status;
            $changes = ['status' => $next];

            if ($next === 'agreement') {
                $changes['agreement_date'] = $data['agreement_date'];
            }

            if ($next === 'cancelled') {
                $changes['cancellation_reason'] = $data['reason'];
            }

            $sale->update($changes);

            $sale->statusHistory()->create([
                'from_status' => $previous,
                'to_status' => $next,
                'notes' => $data['reason'] ?? $data['notes'] ?? null,
                'changed_by' => $user->id,
            ]);

            return $sale;
        });

        return response()->json([
            'message' => 'Sale status updated successfully.',
            'sale' => $updated->fresh(),
        ]);
    }

    public function history(Request $request, PropertySale $sale): JsonResponse
    {
        abort_unless($this->canView($request->user(), $sale), 404);

        return response()->json([
            'history' => $sale->statusHistory()
                ->with('changedBy:id,name')
                ->orderBy('id')
                ->get(),
        ]);
    }
}
