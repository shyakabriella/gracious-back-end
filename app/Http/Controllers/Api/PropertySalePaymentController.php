<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertySale;
use App\Models\PropertySalePayment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertySalePaymentController extends Controller
{
    private function isAdmin(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function canView(User $user, PropertySale $sale): bool
    {
        return $this->isAdmin($user)
            || ($user->hasRole('agent')
                && (int) $sale->agent_id === $user->id);
    }

    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100
            + (int) str_pad($fraction, 2, '0');
    }

    public function index(Request $request, PropertySale $sale): JsonResponse
    {
        abort_unless($this->canView($request->user(), $sale), 404);

        $payments = $sale->payments()
            ->with(['recorder:id,name', 'verifier:id,name'])
            ->orderByDesc('id')
            ->get();

        $verified = $sale->payments()
            ->where('status', 'verified')
            ->selectRaw('COALESCE(SUM(amount), 0) AS total')
            ->value('total');

        $balanceCents = max(
            0,
            $this->cents((string) $sale->agreed_price)
                - $this->cents((string) $verified)
        );

        return response()->json([
            'payments' => $payments,
            'summary' => [
                'currency' => $sale->currency,
                'agreed_price' => $sale->agreed_price,
                'verified_amount' => number_format(
                    $this->cents((string) $verified) / 100, 2, '.', ''
                ),
                'balance' => number_format(
                    $balanceCents / 100, 2, '.', ''
                ),
            ],
        ]);
    }

    public function store(Request $request, PropertySale $sale): JsonResponse
    {
        abort_unless($this->canView($request->user(), $sale), 404);

        $data = $request->validate([
            'payment_type' => ['required', 'in:deposit,installment,final'],
            'payment_method' => [
                'required',
                'in:cash,bank_transfer,mobile_money,cheque,other',
            ],
            'amount' => [
                'required', 'numeric', 'gt:0',
                'decimal:0,2', 'max:9999999999999999.99',
            ],
            'transaction_reference' => [
                'nullable', 'string', 'max:150',
                'required_unless:payment_method,cash',
            ],
            'payment_date' => [
                'required', 'date', 'before_or_equal:today',
            ],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $payment = DB::transaction(function () use ($sale, $data, $request) {
            $sale = PropertySale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            abort_unless($this->canView($request->user(), $sale), 404);

            if (!in_array($sale->status, [
                'agreement', 'pending_completion',
            ], true)) {
                throw ValidationException::withMessages([
                    'sale' => [
                        'Payments can only be recorded after sale agreement.',
                    ],
                ]);
            }

            $reference = trim($data['transaction_reference'] ?? '');

            if (
                $reference !== ''
                && PropertySalePayment::query()
                    ->where('payment_method', $data['payment_method'])
                    ->where('transaction_reference', $reference)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'transaction_reference' => [
                        'This payment reference is already registered.',
                    ],
                ]);
            }

            if ($this->cents((string) $data['amount'])
                > $this->cents((string) $sale->agreed_price)) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Payment amount cannot exceed the agreed sale price.',
                    ],
                ]);
            }

            return $sale->payments()->create([
                'reference' => 'PAY-' . Str::upper(Str::random(16)),
                'payment_type' => $data['payment_type'],
                'payment_method' => $data['payment_method'],
                'amount' => $data['amount'],
                'currency' => $sale->currency,
                'transaction_reference' => $reference ?: null,
                'payment_date' => $data['payment_date'],
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Payment recorded and awaiting verification.',
            'payment' => $payment,
        ], 201);
    }

    public function verify(
        Request $request,
        PropertySale $sale,
        PropertySalePayment $payment
    ): JsonResponse {
        abort_unless($this->isAdmin($request->user()), 403);
        abort_unless((int) $payment->property_sale_id === $sale->id, 404);

        $updated = DB::transaction(function () use ($sale, $payment, $request) {
            $sale = PropertySale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $payment = PropertySalePayment::query()
                ->lockForUpdate()
                ->findOrFail($payment->id);

            abort_unless((int) $payment->property_sale_id === $sale->id, 404);

            if (!in_array($sale->status, [
                'agreement', 'pending_completion',
            ], true)) {
                throw ValidationException::withMessages([
                    'sale' => ['This sale cannot accept verified payments.'],
                ]);
            }

            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['This payment has already been reviewed.'],
                ]);
            }

            if (!$payment->evidences()->exists()) {
                throw ValidationException::withMessages([
                    'evidence' => [
                        'Upload payment evidence before verification.',
                    ],
                ]);
            }

            $verified = $sale->payments()
                ->where('status', 'verified')
                ->selectRaw('COALESCE(SUM(amount), 0) AS total')
                ->value('total');

            $newTotal = $this->cents((string) $verified)
                + $this->cents((string) $payment->amount);

            if ($newTotal > $this->cents((string) $sale->agreed_price)) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'Verifying this payment would exceed the sale price.',
                    ],
                ]);
            }

            $payment->update([
                'status' => 'verified',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);

            $payment->statusHistory()->create([
                'from_status' => 'pending',
                'to_status' => 'verified',
                'notes' => 'Payment evidence reviewed and verified.',
                'changed_by' => $request->user()->id,
            ]);

            return $payment;
        });

        return response()->json([
            'message' => 'Payment verified successfully.',
            'payment' => $updated,
        ]);
    }

    public function reject(
        Request $request,
        PropertySale $sale,
        PropertySalePayment $payment
    ): JsonResponse {
        abort_unless($this->isAdmin($request->user()), 403);
        abort_unless((int) $payment->property_sale_id === $sale->id, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $updated = DB::transaction(function () use ($sale, $payment, $data, $request) {
            $sale = PropertySale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $payment = PropertySalePayment::query()
                ->lockForUpdate()
                ->findOrFail($payment->id);

            abort_unless((int) $payment->property_sale_id === $sale->id, 404);

            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['This payment has already been reviewed.'],
                ]);
            }

            $payment->update([
                'status' => 'rejected',
                'rejection_reason' => $data['reason'],
                'verified_by' => $request->user()->id,
                'verified_at' => null,
            ]);

            $payment->statusHistory()->create([
                'from_status' => 'pending',
                'to_status' => 'rejected',
                'notes' => $data['reason'],
                'changed_by' => $request->user()->id,
            ]);

            return $payment;
        });

        return response()->json([
            'message' => 'Payment rejected.',
            'payment' => $updated,
        ]);
    }
}
