<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyRental;
use App\Models\PropertyRentPayment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertyRentPaymentController extends Controller
{
    private function canView(User $user, PropertyRental $rental): bool
    {
        if (!$user->can('rentals.view')) {
            return false;
        }

        return $user->hasAnyRole([
            'super_admin', 'admin',
            'property_manager', 'accountant',
        ]) || (
            $user->hasRole('agent')
            && (int) $rental->agent_id === (int) $user->id
        );
    }

    private function canRecord(User $user, PropertyRental $rental): bool
    {
        return $user->can('rentals.update')
            && $user->hasAnyRole([
                'super_admin', 'admin', 'property_manager',
            ]);
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(
            explode('.', $amount, 2), 2, '0'
        );

        return (int) $whole * 100
            + (int) str_pad($fraction, 2, '0');
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100)
            . '.'
            . str_pad(
                (string) ($cents % 100),
                2, '0', STR_PAD_LEFT
            );
    }

    public function index(
        Request $request,
        PropertyRental $rental
    ): JsonResponse {
        abort_unless(
            $this->canView($request->user(), $rental),
            404
        );

        $payments = $rental->payments()
            ->with([
                'recorder:id,name',
                'verifier:id,name',
            ])
            ->latest('id')
            ->get();

        $billed = $rental->rentSchedules()
            ->selectRaw('COALESCE(SUM(amount_due), 0) AS total')
            ->value('total');

        // Only allocations linked to verified payments count.
        $verified = DB::table('property_rent_payment_allocations as a')
            ->join(
                'property_rent_payments as p',
                'p.id',
                '=',
                'a.property_rent_payment_id'
            )
            ->join(
                'property_rent_schedules as s',
                's.id',
                '=',
                'a.property_rent_schedule_id'
            )
            ->where('p.property_rental_id', $rental->id)
            ->where('s.property_rental_id', $rental->id)
            ->where('p.status', 'verified')
            ->selectRaw('COALESCE(SUM(a.amount), 0) AS total')
            ->value('total');

        $balanceCents = max(
            0,
            $this->cents((string) $billed)
                - $this->cents((string) $verified)
        );

        return response()->json([
            'payments' => $payments,
            'summary' => [
                'currency' => $rental->currency,
                'total_billed' => $this->money(
                    $this->cents((string) $billed)
                ),
                'verified_paid' => $this->money(
                    $this->cents((string) $verified)
                ),
                'outstanding_balance' => $this->money(
                    $balanceCents
                ),
            ],
        ]);
    }

    public function store(
        Request $request,
        PropertyRental $rental
    ): JsonResponse {
        abort_unless(
            $this->canRecord($request->user(), $rental),
            403
        );

        $data = $request->validate([
            'payment_method' => [
                'required',
                'in:cash,bank_transfer,mobile_money,cheque,other',
            ],
            'amount' => [
                'required', 'numeric', 'gt:0',
                'decimal:0,2',
                'max:9999999999999.99',
            ],
            'transaction_reference' => [
                'nullable', 'string', 'max:150',
                'required_unless:payment_method,cash',
            ],
            'payment_date' => [
                'required', 'date', 'before_or_equal:today',
            ],
            'notes' => [
                'nullable', 'string', 'max:3000',
            ],
        ]);

        $payment = DB::transaction(function () use (
            $request,
            $rental,
            $data
        ) {
            $rental = PropertyRental::query()
                ->lockForUpdate()
                ->findOrFail($rental->id);

            if (!in_array(
                $rental->status,
                ['active', 'terminated', 'expired'],
                true
            )) {
                throw ValidationException::withMessages([
                    'rental' => [
                        'Payments require an active or ended lease.',
                    ],
                ]);
            }

            $reference = trim(
                $data['transaction_reference'] ?? ''
            );

            if (
                $reference !== ''
                && PropertyRentPayment::query()
                    ->where(
                        'payment_method',
                        $data['payment_method']
                    )
                    ->where(
                        'transaction_reference',
                        $reference
                    )
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'transaction_reference' => [
                        'This payment reference is already registered.',
                    ],
                ]);
            }

            $amount = $this->cents(
                (string) $data['amount']
            );

            $statement = app(
                \App\Services\PropertyRentStatementService::class
            )->build($rental);

            $outstanding = $this->cents(
                $statement['summary']['total_outstanding']
            );

            if ($outstanding <= 0 || $amount > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Payment cannot exceed the adjusted '
                        . 'outstanding rental balance.',
                    ],
                ]);
            }

            $payment = $rental->payments()->create([
                'reference' => 'RPAY-'
                    . Str::upper(Str::random(16)),
                'payment_method' => $data['payment_method'],
                'amount' => $data['amount'],
                'currency' => $rental->currency,
                'transaction_reference' => $reference ?: null,
                'payment_date' => $data['payment_date'],
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            $payment->statusHistory()->create([
                'from_status' => null,
                'to_status' => 'pending',
                'notes' => 'Rental payment recorded.',
                'changed_by' => $request->user()->id,
            ]);

            return $payment;
        }, 3);

        return response()->json([
            'message' => 'Rental payment recorded and awaiting verification.',
            'payment' => $payment,
        ], 201);
    }
}
