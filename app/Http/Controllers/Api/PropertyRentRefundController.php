<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyRental;
use App\Models\PropertyRentPayment;
use App\Models\PropertyRentRefund;
use App\Services\PropertyRentOverpaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertyRentRefundController extends Controller
{
    private function canView(Request $request, PropertyRental $rental): bool
    {
        $user = $request->user();

        return $user->can('rentals.view')
            && (
                $user->hasAnyRole([
                    'super_admin',
                    'admin',
                    'property_manager',
                    'accountant',
                ])
                || (
                    $user->hasRole('agent')
                    && (int) $rental->agent_id === (int) $user->id
                )
            );
    }

    private function canRequest(Request $request): bool
    {
        return $request->user()->can('rentals.update')
            && $request->user()->hasAnyRole([
                'super_admin',
                'admin',
                'property_manager',
            ]);
    }

    private function cents(string $value): int
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages([
                'amount' => ['Invalid refund amount.'],
            ]);
        }

        [$whole, $fraction] = array_pad(
            explode('.', $value, 2), 2, '0'
        );

        return (int) $whole * 100
            + (int) str_pad($fraction, 2, '0');
    }

    public function index(
        Request $request,
        PropertyRental $rental
    ): JsonResponse {
        abort_unless($this->canView($request, $rental), 404);

        return response()->json([
            'refunds' => PropertyRentRefund::query()
                ->where('property_rental_id', $rental->id)
                ->with([
                    'requester:id,name',
                    'approver:id,name',
                    'processor:id,name',
                ])
                ->latest('id')
                ->get(),
            'balance' => app(
                PropertyRentOverpaymentService::class
            )->calculate($rental),
        ]);
    }

    public function store(
        Request $request,
        PropertyRental $rental
    ): JsonResponse {
        abort_unless($this->canRequest($request), 403);

        $data = $request->validate([
            'property_rent_payment_id' => [
                'required',
                'integer',
                'exists:property_rent_payments,id',
            ],
            'amount' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,2',
                'max:9999999999999.99',
            ],
            'reason' => [
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
        ]);

        $refund = DB::transaction(function () use (
            $request, $rental, $data
        ) {
            // All refund requests for this rental use the same lock.
            $rental = PropertyRental::query()
                ->lockForUpdate()
                ->findOrFail($rental->id);

            if (!in_array(
                $rental->status,
                ['terminated', 'expired'],
                true
            )) {
                throw ValidationException::withMessages([
                    'rental' => [
                        'Refund requests require an ended rental agreement.',
                    ],
                ]);
            }

            $payment = PropertyRentPayment::query()
                ->where('property_rental_id', $rental->id)
                ->whereKey($data['property_rent_payment_id'])
                ->where('status', 'verified')
                ->first();

            if (!$payment) {
                throw ValidationException::withMessages([
                    'property_rent_payment_id' => [
                        'Select a verified payment belonging to this rental.',
                    ],
                ]);
            }

            if ($payment->currency !== $rental->currency) {
                throw ValidationException::withMessages([
                    'property_rent_payment_id' => [
                        'Payment currency does not match the rental.',
                    ],
                ]);
            }

            $balance = app(
                PropertyRentOverpaymentService::class
            )->calculate($rental);

            $requested = $this->cents((string) $data['amount']);
            $available = $this->cents($balance['available_refund']);

            if ($requested > $available) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Refund exceeds the available refundable balance.',
                    ],
                ]);
            }

            // A refund must never claim more than the total
            // amount actually verified for its source payment.
            $previous = PropertyRentRefund::query()
                ->where('property_rent_payment_id', $payment->id)
                ->whereIn('status', [
                    'pending',
                    'approved',
                    'processing',
                    'paid',
                ])
                ->sum('amount');

            $paymentRemaining = $this->cents(
                (string) $payment->amount
            ) - $this->cents((string) $previous);

            if ($requested > $paymentRemaining) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Refund exceeds the unreserved amount '
                        . 'of the selected payment.',
                    ],
                ]);
            }

            $refund = PropertyRentRefund::create([
                'reference' => 'RREF-'
                    . Str::upper(Str::random(20)),
                'property_rental_id' => $rental->id,
                'property_rent_payment_id' => $payment->id,
                'amount' => $data['amount'],
                'currency' => $rental->currency,
                'status' => 'pending',
                'reason' => trim($data['reason']),
                'requested_by' => $request->user()->id,
            ]);

            $refund->statusHistory()->create([
                'from_status' => null,
                'to_status' => 'pending',
                'notes' => 'Refund requested for verified rental overpayment.',
                'changed_by' => $request->user()->id,
            ]);

            return $refund;
        }, 3);

        return response()->json([
            'message' => 'Refund request submitted for approval.',
            'refund' => $refund,
        ], 201);
    }
}
