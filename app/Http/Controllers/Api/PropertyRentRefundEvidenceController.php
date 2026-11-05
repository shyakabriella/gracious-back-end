<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyRental;
use App\Models\PropertyRentRefund;
use App\Models\PropertyRentRefundEvidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertyRentRefundEvidenceController extends Controller
{
    private function checkAccess(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund
    ): void {
        $user = $request->user();

        abort_unless(
            $user->can('rentals.view')
            && $user->hasAnyRole([
                'super_admin',
                'admin',
                'property_manager',
                'accountant',
            ]),
            403
        );

        abort_unless(
            (int) $refund->property_rental_id === (int) $rental->id,
            404
        );
    }

    public function index(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund
    ): JsonResponse {
        $this->checkAccess($request, $rental, $refund);

        return response()->json([
            'evidences' => $refund->evidences()
                ->with([
                    'uploader:id,name',
                    'verifier:id,name',
                ])
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund
    ): JsonResponse {
        $this->checkAccess($request, $rental, $refund);

        abort_unless(
            $request->user()->can('rentals.update')
            && $request->user()->hasAnyRole([
                'super_admin',
                'admin',
                'property_manager',
            ]),
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

        $path = null;

        try {
            $evidence = DB::transaction(function () use (
                $request,
                $rental,
                $refund,
                $data,
                &$path
            ) {
                $rental = PropertyRental::query()
                    ->lockForUpdate()
                    ->findOrFail($rental->id);

                $refund = PropertyRentRefund::query()
                    ->lockForUpdate()
                    ->findOrFail($refund->id);

                $this->checkAccess($request, $rental, $refund);

                if (!in_array(
                    $refund->status,
                    ['approved', 'processing'],
                    true
                )) {
                    throw ValidationException::withMessages([
                        'refund' => [
                            'Evidence requires an approved '
                            . 'or processing refund.',
                        ],
                    ]);
                }

                $file = $data['file'];

                $path = $file->store(
                    'rent-refund-evidence/' . $refund->id,
                    'local'
                );

                if (!$path) {
                    throw new \RuntimeException(
                        'Refund evidence could not be stored.'
                    );
                }

                $hash = hash_file(
                    'sha256',
                    Storage::disk('local')->path($path)
                );

                if ($hash === false) {
                    throw new \RuntimeException(
                        'Refund evidence hash could not be calculated.'
                    );
                }

                return $refund->evidences()->create([
                    'original_name' => Str::limit(
                        basename($file->getClientOriginalName()),
                        240,
                        ''
                    ),
                    'disk' => 'local',
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'sha256' => $hash,
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
            'message' => 'Refund evidence uploaded successfully.',
            'evidence' => $evidence,
        ], 201);
    }

    public function download(
        Request $request,
        PropertyRental $rental,
        PropertyRentRefund $refund,
        PropertyRentRefundEvidence $evidence
    ) {
        $this->checkAccess($request, $rental, $refund);

        abort_unless(
            (int) $evidence->property_rent_refund_id
                === (int) $refund->id,
            404
        );

        abort_unless(
            $evidence->disk === 'local'
            && Storage::disk('local')->exists(
                $evidence->file_path
            ),
            404
        );

        $actualHash = hash_file(
            'sha256',
            Storage::disk('local')->path(
                $evidence->file_path
            )
        );

        abort_unless(
            $actualHash !== false
            && hash_equals($evidence->sha256, $actualHash),
            409
        );

        return Storage::disk('local')->download(
            $evidence->file_path,
            $evidence->original_name
        );
    }
}
