<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyRental;
use App\Models\PropertyRentRefund;
use App\Services\PropertyRentOverpaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PropertyRentRefundApprovalController extends Controller
{
    private function authorizeReviewer(Request $request): void
    {
        abort_unless(
            $request->user()->can('rentals.update')
            && $request->user()->hasAnyRole([
                'super_admin',
                'admin',
            ]),
            403
        );
    }

    public function approve(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund
    ): JsonResponse {
        $this->authorizeReviewer($request);

        abort_unless(
            (int) $refund->property_rental_id === (int) $rental->id,
            404
        );

        $updated = DB::transaction(function () use (
            $request, $rental, $refund
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

            if ($refund->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only pending refunds can be approved.',
                    ],
                ]);
            }

            if ((int) $refund->requested_by ===
                (int) $request->user()->id) {
                throw ValidationException::withMessages([
                    'approval' => [
                        'You cannot approve your own refund request.',
                    ],
                ]);
            }

            if (!in_array(
                $rental->status,
                ['terminated', 'expired'],
                true
            )) {
                throw ValidationException::withMessages([
                    'rental' => [
                        'Refund approval requires an ended rental.',
                    ],
                ]);
            }

            $balance = app(
                PropertyRentOverpaymentService::class
            )->calculate($rental);

            // Pending refunds already reserve their full amount.
            // Their reservation must be included in total coverage.
            $gross = (int) round(
                (float) $balance['overpayment'] * 100
            );

            $reserved = (int) round(
                (float) $balance['reserved_refunds'] * 100
            );

            if ($reserved > $gross) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Refund reservations exceed available overpayment.',
                    ],
                ]);
            }

            $refund->forceFill([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $refund->statusHistory()->create([
                'from_status' => 'pending',
                'to_status' => 'approved',
                'notes' => 'Refund approved after financial review.',
                'changed_by' => $request->user()->id,
            ]);

            return $refund->fresh([
                'requester:id,name',
                'approver:id,name',
            ]);
        }, 3);

        return response()->json([
            'message' => 'Refund approved. Payment has not been issued.',
            'refund' => $updated,
        ]);
    }

    public function reject(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund
    ): JsonResponse {
        $this->authorizeReviewer($request);

        abort_unless(
            (int) $refund->property_rental_id === (int) $rental->id,
            404
        );

        $data = $request->validate([
            'reason' => [
                'required', 'string', 'min:5', 'max:2000',
            ],
        ]);

        $updated = DB::transaction(function () use (
            $request, $rental, $refund, $data
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

            if ($refund->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only pending refunds can be rejected.',
                    ],
                ]);
            }

            $refund->forceFill([
                'status' => 'rejected',
                'rejection_reason' => $data['reason'],
            ])->save();

            $refund->statusHistory()->create([
                'from_status' => 'pending',
                'to_status' => 'rejected',
                'notes' => $data['reason'],
                'changed_by' => $request->user()->id,
            ]);

            return $refund->fresh([
                'requester:id,name',
                'approver:id,name',
            ]);
        }, 3);

        return response()->json([
            'message' => 'Refund request rejected.',
            'refund' => $updated,
        ]);
    }
}
