<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class PropertyDocumentController extends Controller
{
    private function canView(Request $request, Property $property): bool
    {
        $user = $request->user();

        return $user->can('properties.view') && (
            $user->hasAnyRole(['super_admin', 'admin']) ||
            $property->created_by === $user->id
        );
    }

    private function canEdit(Request $request, Property $property): bool
    {
        $user = $request->user();

        return $user->can('properties.update') && (
            $user->hasAnyRole(['super_admin', 'admin']) ||
            $property->created_by === $user->id
        );
    }

    private function checkDocument(
        Property $property,
        PropertyDocument $document
    ): void {
        abort_unless($document->property_id === $property->id, 404);
    }

    private function documentData(PropertyDocument $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'created_at' => $document->created_at,
        ];
    }

    public function index(Request $request, Property $property): JsonResponse
    {
        abort_unless($this->canView($request, $property), 403);

        return response()->json([
            'documents' => $property->documents()
                ->latest()
                ->get()
                ->map(fn ($document) => $this->documentData($document)),
        ]);
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        abort_unless($this->canEdit($request, $property), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'document_type' => [
                'required',
                Rule::in([
                    'ownership',
                    'authorization',
                    'inspection',
                    'agreement',
                    'other',
                ]),
            ],
            'file' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png'])
                    ->max('10mb'),
            ],
        ]);

        $file = $request->file('file');

        $path = $file->store(
            "properties/{$property->id}/documents",
            'local'
        );

        try {
            $document = $property->documents()->create([
                'title' => $data['title'],
                'document_type' => $data['document_type'],
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json([
            'message' => 'Document uploaded successfully',
            'document' => $this->documentData($document),
        ], 201);
    }

    public function download(
        Request $request,
        Property $property,
        PropertyDocument $document
    ) {
        $this->checkDocument($property, $document);

        abort_unless($this->canView($request, $property), 403);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($document->file_path), 404);

        return $disk->download(
            $document->file_path,
            'property-document-' . $document->id .
                '.' . pathinfo($document->file_path, PATHINFO_EXTENSION),
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function destroy(
        Request $request,
        Property $property,
        PropertyDocument $document
    ): JsonResponse {
        $this->checkDocument($property, $document);

        abort_unless($this->canEdit($request, $property), 403);

        $path = $document->file_path;

        $document->delete();

        Storage::disk('local')->delete($path);

        return response()->json([
            'message' => 'Document deleted successfully',
        ]);
    }
}
