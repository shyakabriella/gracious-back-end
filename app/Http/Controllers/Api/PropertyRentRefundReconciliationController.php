<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyRental;
use App\Models\PropertyRentRefund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * MANUAL reconciliation. Finance reviewer MUST independently verify
 * bank/provider records outside this application before submitting.
 * This controller cannot access banking systems or prove external settlement.
 */
class PropertyRentRefundReconciliationController extends Controller
{
    private function cents(string $value): int
    {
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([
                'confirmed_amount' => ['Invalid monetary precision.'],
            ]);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public function reconcile(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund
    ): JsonResponse {
        $user = $request->user();
        abort_unless(
            $user->can('rentals.update')
            && $user->hasAnyRole(['admin', 'super_admin']),
            403
        );
        abort_unless(
            (int) $refund->property_rental_id === (int) $rental->id,
            404
        );

        $data = $request->validate([
            'evidence_id' => ['required', 'integer'],
            'confirmation_source' => ['required', 'in:bank_statement,provider_portal'],
            'provider_transaction_reference' => ['required', 'string', 'min:5', 'max:255'],
            'confirmed_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['required', 'string', 'max:10'],
            'confirmed_recipient' => ['required', 'string', 'max:255'],
            'provider_confirmed_at' => ['required', 'date', 'before_or_equal:now'],
            'outcome' => ['required', 'in:success,failed'],
            'verification_notes' => ['required', 'string', 'min:20', 'max:3000'],
            'external_record_checked' => ['required', 'accepted'],
        ]);

        $result = DB::transaction(function () use ($rental, $refund, $data, $user) {
            $rental = PropertyRental::query()->lockForUpdate()->findOrFail($rental->id);
            $refund = PropertyRentRefund::query()->lockForUpdate()->findOrFail($refund->id);
            abort_unless(
                (int) $refund->property_rental_id === (int) $rental->id,
                404
            );
            if ($refund->status !== 'processing' || $refund->reconciliation()->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Only an unreconciled processing refund may be confirmed.'],
                ]);
            }
            $destination = $refund->destination()->lockForUpdate()->first();
            if (!$destination || $destination->status !== 'approved'
                || $destination->refund_method !== $refund->refund_method) {
                throw ValidationException::withMessages([
                    'destination' => ['An approved matching destination is required.'],
                ]);
            }
            foreach (['requested_by', 'approved_by', 'processed_by'] as $field) {
                if ((int) $refund->{$field} === (int) $user->id) {
                    throw ValidationException::withMessages([
                        'verification' => ['Reviewer must be independent of requester, approver and processor.'],
                    ]);
                }
            }
            if ((int) $destination->recorded_by === (int) $user->id
                || (int) $destination->approved_by === (int) $user->id) {
                throw ValidationException::withMessages([
                    'verification' => ['Reviewer must also be independent of destination handling.'],
                ]);
            }
            $evidence = $refund->evidences()
                ->lockForUpdate()->find($data['evidence_id']);
            if (!$evidence || $evidence->disk !== 'local'
                || !Storage::disk('local')->exists($evidence->file_path)) {
                throw ValidationException::withMessages([
                    'evidence_id' => ['Private evidence from this refund is required.'],
                ]);
            }
            if ((int) $evidence->uploaded_by === (int) $user->id) {
                throw ValidationException::withMessages([
                    'verification' => ['Evidence uploader cannot verify that evidence.'],
                ]);
            }
            $hash = hash_file('sha256', Storage::disk('local')->path($evidence->file_path));
            if ($hash === false || !hash_equals($evidence->sha256, $hash)) {
                throw ValidationException::withMessages([
                    'evidence_id' => ['Evidence integrity verification failed.'],
                ]);
            }
            if ($this->cents((string) $refund->amount)
                !== $this->cents((string) $data['confirmed_amount'])
                || $refund->currency !== $data['currency']) {
                throw ValidationException::withMessages([
                    'confirmed_amount' => ['Amount and currency must equal approved refund.'],
                ]);
            }
            $reference = trim($data['provider_transaction_reference']);
            if (!hash_equals((string) $refund->transaction_reference, $reference)) {
                throw ValidationException::withMessages([
                    'provider_transaction_reference' => ['Provider reference must match processing record.'],
                ]);
            }
            if (!hash_equals(trim((string) $destination->recipient_identifier), trim($data['confirmed_recipient']))) {
                throw ValidationException::withMessages([
                    'confirmed_recipient' => ['Verified recipient must match approved destination.'],
                ]);
            }
            if (DB::table('property_rent_refund_reconciliations')
                ->where('confirmation_source', $data['confirmation_source'])
                ->where('provider_transaction_reference', $reference)
                ->exists()) {
                throw ValidationException::withMessages([
                    'provider_transaction_reference' => ['This confirmation has already been used.'],
                ]);
            }
            $reconciliation = $refund->reconciliation()->create([
                'evidence_id' => $evidence->id,
                'confirmation_source' => $data['confirmation_source'],
                'provider_transaction_reference' => $reference,
                'confirmed_amount' => $data['confirmed_amount'],
                'currency' => $data['currency'],
                'outcome' => $data['outcome'],
                'verified_by' => $user->id,
                'verified_at' => now(),
                'verification_notes' => trim($data['verification_notes']),
                'confirmed_recipient' => trim($data['confirmed_recipient']),
                'provider_confirmed_at' => $data['provider_confirmed_at'],
            ]);
            $evidence->forceFill([
                'verified_by' => $user->id,
                'verified_at' => now(),
            ])->save();
            $status = $data['outcome'] === 'success' ? 'paid' : 'failed';
            $refund->forceFill([
                'status' => $status,
                'refunded_at' => $status === 'paid' ? now() : null,
            ])->save();
            $refund->statusHistory()->create([
                'from_status' => 'processing',
                'to_status' => $status,
                'notes' => 'Manual external-record reconciliation: '.$data['confirmation_source'],
                'changed_by' => $user->id,
            ]);
            return [$refund->fresh(), $reconciliation];
        }, 3);
        return response()->json([
            'message' => 'Manual reconciliation recorded. External record review was attested by an independent officer.',
            'refund' => $result[0],
            'reconciliation_id' => $result[1]->id,
        ]);
    }
}
