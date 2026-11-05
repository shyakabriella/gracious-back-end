<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertySale;
use App\Models\PropertySalePayment;
use App\Models\PropertySalePaymentEvidence;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertySalePaymentEvidenceController extends Controller
{
    private function canAccess(User $user, PropertySale $sale): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin'])
            || ($user->hasRole('agent')
                && (int) $sale->agent_id === (int) $user->id);
    }

    private function checkAccess(
        Request $request,
        PropertySale $sale,
        PropertySalePayment $payment
    ): void {
        abort_unless($this->canAccess($request->user(), $sale), 404);

        abort_unless(
            (int) $payment->property_sale_id === (int) $sale->id,
            404
        );
    }

    public function index(
        Request $request,
        PropertySale $sale,
        PropertySalePayment $payment
    ): JsonResponse {
        $this->checkAccess($request, $sale, $payment);

        return response()->json([
            'evidences' => $payment->evidences()
                ->with('uploader:id,name')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        PropertySale $sale,
        PropertySalePayment $payment
    ): JsonResponse {
        $this->checkAccess($request, $sale, $payment);

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:5120',
            ],
        ]);

        $file = $data['file'];
        $path = null;

        try {
            $evidence = DB::transaction(function () use (
                $request,
                $sale,
                $payment,
                $file,
                &$path
            ) {
                $sale = PropertySale::query()
                    ->lockForUpdate()
                    ->findOrFail($sale->id);

                $payment = PropertySalePayment::query()
                    ->lockForUpdate()
                    ->findOrFail($payment->id);

                $this->checkAccess($request, $sale, $payment);

                if (
                    $payment->status !== 'pending'
                    || !in_array($sale->status, [
                        'agreement',
                        'pending_completion',
                    ], true)
                ) {
                    throw ValidationException::withMessages([
                        'file' => [
                            'Evidence can only be uploaded for a pending payment on an active agreement.',
                        ],
                    ]);
                }

                $path = $file->store(
                    'sale-payment-evidence/' . $payment->id,
                    'local'
                );

                if (!$path) {
                    throw new \RuntimeException(
                        'Payment evidence could not be stored.'
                    );
                }

                return $payment->evidences()->create([
                    'original_name' => Str::limit(
                        basename($file->getClientOriginalName()),
                        240,
                        ''
                    ),
                    'disk' => 'local',
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);
            });
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Payment evidence uploaded successfully.',
            'evidence' => $evidence,
        ], 201);
    }

    public function download(
        Request $request,
        PropertySale $sale,
        PropertySalePayment $payment,
        PropertySalePaymentEvidence $evidence
    ) {
        $this->checkAccess($request, $sale, $payment);

        abort_unless(
            (int) $evidence->property_sale_payment_id
                === (int) $payment->id,
            404
        );

        abort_unless(
            $evidence->disk === 'local'
                && Storage::disk('local')->exists($evidence->file_path),
            404
        );

        return Storage::disk('local')->download(
            $evidence->file_path,
            $evidence->original_name
        );
    }
}
