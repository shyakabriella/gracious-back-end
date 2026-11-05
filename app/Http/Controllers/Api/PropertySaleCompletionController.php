<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertySale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PropertySaleCompletionController extends Controller
{
    private function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(
            explode('.', $amount, 2),
            2,
            '0'
        );

        return (int) $whole * 100
            + (int) str_pad($fraction, 2, '0');
    }

    public function complete(
        Request $request,
        PropertySale $sale
    ): JsonResponse {
        abort_unless(
            $request->user()->hasAnyRole(['super_admin', 'admin']),
            403
        );

        $data = $request->validate([
            'confirm_documents_reviewed' => ['required', 'accepted'],
            'confirm_transfer_registered' => ['required', 'accepted'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $completed = DB::transaction(function () use (
            $request, $sale, $data
        ) {
            $sale = PropertySale::query()
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $property = Property::query()
                ->lockForUpdate()
                ->findOrFail($sale->property_id);

            if ($sale->status !== 'pending_completion') {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only a sale pending completion can be completed.',
                    ],
                ]);
            }

            if (
                $property->listing_type !== 'sale'
                || $property->availability_status !== 'available'
            ) {
                throw ValidationException::withMessages([
                    'property' => [
                        'The property is not available for this sale.',
                    ],
                ]);
            }

            $payments = $sale->payments()
                ->where('status', 'verified')
                ->with(['evidences', 'statusHistory'])
                ->get();

            if ($payments->isEmpty()) {
                throw ValidationException::withMessages([
                    'payments' => ['No verified payments found.'],
                ]);
            }

            if ($sale->payments()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages([
                    'payments' => [
                        'Review all pending payments before completion.',
                    ],
                ]);
            }

            $total = 0;

            foreach ($payments as $payment) {
                if ($payment->currency !== $sale->currency) {
                    throw ValidationException::withMessages([
                        'payments' => ['Payment currency does not match the sale.'],
                    ]);
                }

                if (
                    !$payment->verified_by
                    || !$payment->verified_at
                    || !$payment->statusHistory->contains(
                        'to_status',
                        'verified'
                    )
                ) {
                    throw ValidationException::withMessages([
                        'payments' => [
                            'A verified payment is missing its approval audit.',
                        ],
                    ]);
                }

                $hasEvidence = $payment->evidences->contains(
                    fn ($evidence) =>
                        $evidence->disk === 'local'
                        && Storage::disk('local')->exists(
                            $evidence->file_path
                        )
                );

                if (!$hasEvidence) {
                    throw ValidationException::withMessages([
                        'payments' => [
                            'A verified payment has no available receipt.',
                        ],
                    ]);
                }

                $total += $this->cents((string) $payment->amount);
            }

            if ($total !== $this->cents((string) $sale->agreed_price)) {
                throw ValidationException::withMessages([
                    'payments' => [
                        'Verified payments must equal the agreed sale price.',
                    ],
                ]);
            }

            $documents = $sale->completionDocuments()->get();

            foreach (['signed_agreement', 'ownership_transfer'] as $type) {
                $valid = $documents->contains(
                    fn ($document) =>
                        $document->document_type === $type
                        && $document->disk === 'local'
                        && Storage::disk('local')->exists(
                            $document->file_path
                        )
                );

                if (!$valid) {
                    throw ValidationException::withMessages([
                        'documents' => [
                            "A valid {$type} document is required.",
                        ],
                    ]);
                }
            }

            $sale->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $sale->statusHistory()->create([
                'from_status' => 'pending_completion',
                'to_status' => 'completed',
                'notes' => $data['notes']
                    ?? 'Payment reconciled and legal transfer confirmed.',
                'changed_by' => $request->user()->id,
            ]);

            $property->forceFill([
                'availability_status' => 'sold',
                'is_published' => false,
                'is_featured' => false,
            ])->save();

            return $sale->fresh([
                'property',
                'customer',
                'agent',
            ]);
        });

        return response()->json([
            'message' => 'Property sale completed successfully.',
            'sale' => $completed,
        ]);
    }
}
