<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyRental;
use App\Models\PropertyRentPayment;
use App\Models\PropertyRentPaymentEvidence;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertyRentPaymentEvidenceController extends Controller
{
    private function canView(User $user, PropertyRental $rental): bool
    {
        return $user->can('rentals.view')
            && (
                $user->hasAnyRole([
                    'super_admin', 'admin',
                    'property_manager', 'accountant',
                ])
                || (
                    $user->hasRole('agent')
                    && (int) $rental->agent_id === (int) $user->id
                )
            );
    }

    private function canUpload(User $user): bool
    {
        return $user->can('rentals.update')
            && $user->hasAnyRole([
                'super_admin', 'admin', 'property_manager',
            ]);
    }

    private function checkAccess(
        Request $request,
        PropertyRental $rental,
        PropertyRentPayment $payment
    ): void {
        abort_unless(
            $this->canView($request->user(), $rental),
            404
        );

        abort_unless(
            (int) $payment->property_rental_id
                === (int) $rental->id,
            404
        );
    }

    public function index(
        Request $request,
        PropertyRental $rental,
        PropertyRentPayment $payment
    ): JsonResponse {
        $this->checkAccess($request, $rental, $payment);

        return response()->json([
            'evidences' => $payment->evidences()
                ->with('uploader:id,name')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        PropertyRental $rental,
        PropertyRentPayment $payment
    ): JsonResponse {
        $this->checkAccess($request, $rental, $payment);

        abort_unless(
            $this->canUpload($request->user()),
            403
        );

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
                $rental,
                $payment,
                $file,
                &$path
            ) {
                $rental = PropertyRental::query()
                    ->lockForUpdate()
                    ->findOrFail($rental->id);

                $payment = PropertyRentPayment::query()
                    ->lockForUpdate()
                    ->findOrFail($payment->id);

                $this->checkAccess($request, $rental, $payment);

                if ($payment->status !== 'pending') {
                    throw ValidationException::withMessages([
                        'file' => [
                            'Evidence can only be uploaded for pending payments.',
                        ],
                    ]);
                }

                $path = $file->store(
                    'rent-payment-evidence/' . $payment->id,
                    'local'
                );

                if (!$path) {
                    throw new \RuntimeException(
                        'Rental payment evidence could not be stored.'
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
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Rental payment evidence uploaded successfully.',
            'evidence' => $evidence,
        ], 201);
    }

    public function download(
        Request $request,
        PropertyRental $rental,
        PropertyRentPayment $payment,
        PropertyRentPaymentEvidence $evidence
    ) {
        $this->checkAccess($request, $rental, $payment);

        abort_unless(
            (int) $evidence->property_rent_payment_id
                === (int) $payment->id,
            404
        );

        abort_unless(
            $evidence->disk === 'local'
                && Storage::disk('local')
                    ->exists($evidence->file_path),
            404
        );

        return Storage::disk('local')->download(
            $evidence->file_path,
            $evidence->original_name
        );
    }
}
