<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyRental;
use App\Models\PropertyRentRefund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PropertyRentRefundProcessingController extends Controller
{
    public function start(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user->can('rentals.update')
            && $user->hasAnyRole([
                'super_admin',
                'admin',
                'accountant',
            ]),
            403
        );

        abort_unless(
            (int) $refund->property_rental_id
                === (int) $rental->id,
            404
        );

        $data = $request->validate([
            'refund_method' => [
                'required',
                'in:bank_transfer,mobile_money',
            ],
            'transaction_reference' => [
                'required',
                'string',
                'min:5',
                'max:255',
            ],
        ]);

        $updated = DB::transaction(function () use (
            $request,
            $rental,
            $refund,
            $data
        ) {
            $rental = PropertyRental::query()
                ->lockForUpdate()
                ->findOrFail($rental->id);

            $refund = PropertyRentRefund::query()
                ->lockForUpdate()
                ->findOrFail($refund->id);

            abort_unless(
                (int) $refund->property_rental_id
                    === (int) $rental->id,
                404
            );

            if ($refund->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only approved refunds can enter processing.',
                    ],
                ]);
            }

            if (
                (int) $refund->requested_by === (int) $request->user()->id
                || (int) $refund->approved_by === (int) $request->user()->id
            ) {
                throw ValidationException::withMessages([
                    'processing' => [
                        'Refund processor must be different '
                        . 'from requester and approver.',
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
                        'Refund processing requires an ended rental.',
                    ],
                ]);
            }

            $destination = $refund->destination()
                ->lockForUpdate()
                ->first();

            if (!$destination || $destination->status !== 'approved') {
                throw ValidationException::withMessages([
                    'destination' => [
                        'An approved refund destination is required.',
                    ],
                ]);
            }

            if ($destination->refund_method !== $data['refund_method']) {
                throw ValidationException::withMessages([
                    'refund_method' => [
                        'Transfer method must match approved destination.',
                    ],
                ]);
            }

            $reference = trim($data['transaction_reference']);

            if (strlen($reference) < 5) {
                throw ValidationException::withMessages([
                    'transaction_reference' => [
                        'A valid transfer reference is required.',
                    ],
                ]);
            }

            $exists = PropertyRentRefund::query()
                ->where('refund_method', $data['refund_method'])
                ->where('transaction_reference', $reference)
                ->whereKeyNot($refund->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'transaction_reference' => [
                        'This transfer reference is already used.',
                    ],
                ]);
            }

            $refund->forceFill([
                'status' => 'processing',
                'refund_method' => $data['refund_method'],
                'transaction_reference' => $reference,
                'processed_by' => $request->user()->id,
            ])->save();

            $refund->statusHistory()->create([
                'from_status' => 'approved',
                'to_status' => 'processing',
                'notes' => 'Refund transfer submitted for processing.',
                'changed_by' => $request->user()->id,
            ]);

            return $refund->fresh();
        }, 3);

        return response()->json([
            'message' => 'Refund marked as processing. '
                . 'Independent payout confirmation is still required.',
            'refund' => $updated,
        ]);
    }
}
