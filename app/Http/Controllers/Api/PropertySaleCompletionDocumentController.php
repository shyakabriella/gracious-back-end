<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertySale;
use App\Models\PropertySaleCompletionDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PropertySaleCompletionDocumentController extends Controller
{
    private function isAdmin(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function canView(User $user, PropertySale $sale): bool
    {
        return $this->isAdmin($user)
            || ($user->hasRole('agent')
                && (int) $sale->agent_id === (int) $user->id);
    }

    public function index(Request $request, PropertySale $sale): JsonResponse
    {
        abort_unless($this->canView($request->user(), $sale), 404);

        return response()->json([
            'documents' => $sale->completionDocuments()
                ->with('uploader:id,name')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request, PropertySale $sale): JsonResponse
    {
        abort_unless($this->isAdmin($request->user()), 403);

        $data = $request->validate([
            'document_type' => [
                'required',
                'in:signed_agreement,ownership_transfer',
            ],
            'file' => [
                'required', 'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:10240',
            ],
        ]);

        $path = null;

        try {
            $document = DB::transaction(function () use (
                $request, $sale, $data, &$path
            ) {
                $sale = PropertySale::query()
                    ->lockForUpdate()
                    ->findOrFail($sale->id);

                if (!in_array($sale->status, [
                    'agreement', 'pending_completion',
                ], true)) {
                    throw ValidationException::withMessages([
                        'sale' => [
                            'Completion documents require an active sale agreement.',
                        ],
                    ]);
                }

                $file = $data['file'];

                $path = $file->store(
                    "sale-completion-documents/{$sale->id}",
                    'local'
                );

                if (!$path) {
                    throw new \RuntimeException('Document storage failed.');
                }

                return $sale->completionDocuments()->create([
                    'document_type' => $data['document_type'],
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
            'message' => 'Sale completion document uploaded.',
            'document' => $document,
        ], 201);
    }

    public function download(
        Request $request,
        PropertySale $sale,
        PropertySaleCompletionDocument $document
    ) {
        abort_unless($this->canView($request->user(), $sale), 404);

        abort_unless(
            (int) $document->property_sale_id === (int) $sale->id,
            404
        );

        abort_unless(
            $document->disk === 'local'
                && Storage::disk('local')->exists($document->file_path),
            404
        );

        return Storage::disk('local')->download(
            $document->file_path,
            $document->original_name
        );
    }
}
