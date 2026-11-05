<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyRental;
use App\Models\PropertyRentRefund;
use App\Models\PropertyRentRefundDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PropertyRentRefundDestinationController extends Controller
{
    public function store(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user->can('rentals.update') &&
            $user->hasAnyRole([
                'super_admin', 'admin', 'property_manager',
            ]),
            403
        );

        abort_unless(
            (int) $refund->property_rental_id === (int) $rental->id,
            404
        );

        $data = $request->validate([
            'refund_method' => [
                'required', 'in:bank_transfer,mobile_money',
            ],
            'recipient_name' => [
                'required', 'string', 'min:2', 'max:255',
            ],
            'recipient_identifier' => [
                'required', 'string', 'min:5', 'max:255',
            ],
            'provider_name' => [
                'required', 'string', 'min:2', 'max:150',
            ],
        ]);

        $destination = DB::transaction(function () use (
            $rental, $refund, $data, $user
        ) {
            $rental = PropertyRental::query()
                ->lockForUpdate()
                ->findOrFail($rental->id);

            $refund = PropertyRentRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            abort_unless(
                (int) $refund->property_rental_id === (int) $rental->id,
                404
            );

            if ($refund->status !== 'approved') {
                throw ValidationException::withMessages([
                    'refund' => ['Refund must be approved first.'],
                ]);
            }

            if ($refund->destination()->exists()) {
                throw ValidationException::withMessages([
                    'destination' => [
                        'Destination already exists and cannot be replaced.',
                    ],
                ]);
            }

            return $refund->destination()->create([
                'refund_method' => $data['refund_method'],
                'recipient_name' => trim($data['recipient_name']),
                'recipient_identifier' => trim(
                    $data['recipient_identifier']
                ),
                'provider_name' => trim($data['provider_name']),
                'status' => 'pending',
                'recorded_by' => $user->id,
            ]);
        }, 3);

        return response()->json([
            'message' => 'Destination awaiting independent approval.',
            'destination' => $destination,
        ], 201);
    }

    public function approve(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user->can('rentals.update') &&
            $user->hasAnyRole(['super_admin', 'admin']),
            403
        );

        abort_unless(
            (int) $refund->property_rental_id === (int) $rental->id,
            404
        );

        $destination = DB::transaction(function () use (
            $rental, $refund, $user
        ) {
            $rental = PropertyRental::query()
                ->lockForUpdate()
                ->findOrFail($rental->id);

            $refund = PropertyRentRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            abort_unless(
                (int) $refund->property_rental_id === (int) $rental->id,
                404
            );

            $destination = $refund->destination()
                ->lockForUpdate()
                ->first();

            if (!$destination || $destination->status !== 'pending'
                || $refund->status !== 'approved') {
                throw ValidationException::withMessages([
                    'destination' => [
                        'An approved refund with a pending destination is required.',
                    ],
                ]);
            }

            if (
                (int) $destination->recorded_by === (int) $user->id
                || (int) $refund->requested_by === (int) $user->id
            ) {
                throw ValidationException::withMessages([
                    'approval' => [
                        'Destination approver must be independent.',
                    ],
                ]);
            }

            $destination->forceFill([
                'status' => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            return $destination->fresh();
        }, 3);

        return response()->json([
            'message' => 'Refund destination approved.',
            'destination' => $destination,
        ]);
    }
}
