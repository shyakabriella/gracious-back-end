<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertySubmission;
use App\Models\PropertySubmissionEvidenceFile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffPropertySubmissionEvidenceController extends Controller
{
    /**
     * Ownership documents contain confidential information.
     * Access requires administrator role and verification permission.
     */
    private function authorizeReviewer(Request $request): User
    {
        $user = $request->user();

        abort_unless(
            $user !== null
                && $user->hasAnyRole(['super_admin', 'admin'])
                && $user->can('properties.verify'),
            403
        );

        return $user;
    }

    /**
     * Return safe metadata without exposing private paths.
     */
    private function evidenceData(
        PropertySubmissionEvidenceFile $evidence
    ): array {
        return [
            'id' => $evidence->id,
            'reference' => $evidence->reference,
            'document_type' => $evidence->document_type,
            'title' => $evidence->title,
            'original_name' => $evidence->original_name,
            'mime_type' => $evidence->mime_type,
            'file_size' => $evidence->file_size,
            'sha256' => $evidence->sha256,
            'uploaded_by_name' => $evidence->uploaded_by_name,
            'withdrawn_at' => $evidence->withdrawn_at,
            'created_at' => $evidence->created_at,
        ];
    }

    /**
     * List private evidence metadata for authorized staff.
     */
    public function index(
        Request $request,
        PropertySubmission $submission
    ): JsonResponse {
        $this->authorizeReviewer($request);

        $filters = $request->validate([
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $records = PropertySubmissionEvidenceFile::query()
            ->where('property_submission_id', $submission->id)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        $records->getCollection()->transform(
            fn (PropertySubmissionEvidenceFile $evidence) =>
                $this->evidenceData($evidence)
        );

        return response()->json($records);
    }

    /**
     * Upload a private document for a submission under review.
     *
     * This is for authenticated Gracious administrators,
     * not for public landlord uploads.
     */
    public function store(
        Request $request,
        PropertySubmission $submission
    ): JsonResponse {
        $reviewer = $this->authorizeReviewer($request);

        $data = $request->validate([
            'title' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'document_type' => [
                'required',
                Rule::in([
                    'ownership_title',
                    'owner_authorization',
                ]),
            ],
            'file' => [
                'required',
                File::types([
                    'pdf',
                    'jpg',
                    'jpeg',
                    'png',
                ])->max('10mb'),
            ],

            // All file and audit identifiers are server-owned.
            'reference' => ['prohibited'],
            'property_submission_id' => ['prohibited'],
            'uploaded_by' => ['prohibited'],
            'uploaded_by_name' => ['prohibited'],
            'storage_disk' => ['prohibited'],
            'file_path' => ['prohibited'],
            'mime_type' => ['prohibited'],
            'file_size' => ['prohibited'],
            'sha256' => ['prohibited'],
            'withdrawn_at' => ['prohibited'],
            'withdrawn_by' => ['prohibited'],
            'withdrawn_by_name' => ['prohibited'],
            'withdrawal_reason' => ['prohibited'],
            'status' => ['prohibited'],
            'is_published' => ['prohibited'],
            'converted_property_id' => ['prohibited'],
        ]);

        $file = $request->file('file');
        $storedPath = null;

        try {
            $evidence = DB::transaction(function () use (
                $submission,
                $reviewer,
                $data,
                $file,
                &$storedPath
            ): PropertySubmissionEvidenceFile {
                $locked = PropertySubmission::query()
                    ->lockForUpdate()
                    ->findOrFail($submission->getKey());

                if ($locked->status !== 'under_review') {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Only submissions under review accept evidence.',
                        ],
                    ]);
                }

                if ($locked->converted_property_id !== null) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Converted submissions cannot accept evidence.',
                        ],
                    ]);
                }

                $activeCount =
                    PropertySubmissionEvidenceFile::query()
                        ->where(
                            'property_submission_id',
                            $locked->id
                        )
                        ->whereNull('withdrawn_at')
                        ->count();

                if ($activeCount >= 10) {
                    throw ValidationException::withMessages([
                        'file' => [
                            'A submission can have at most 10 active documents.',
                        ],
                    ]);
                }

                // This is Laravel's private local disk.
                // Never use the public disk for ownership evidence.
                $storedPath = $file->store(
                    "property-submissions/{$locked->id}/evidence",
                    'local'
                );

                if (!$storedPath) {
                    throw new RuntimeException(
                        'Unable to store private evidence.'
                    );
                }

                $disk = Storage::disk('local');

                if (!$disk->exists($storedPath)) {
                    throw new RuntimeException(
                        'Stored evidence file is missing.'
                    );
                }

                $sha256 = hash_file(
                    'sha256',
                    $disk->path($storedPath)
                );

                if ($sha256 === false) {
                    throw new RuntimeException(
                        'Unable to calculate evidence checksum.'
                    );
                }

                $originalName = basename(
                    str_replace(
                        '\\',
                        '/',
                        $file->getClientOriginalName()
                    )
                );

                $originalName = preg_replace(
                    '/[[:cntrl:]]/u',
                    '',
                    $originalName
                ) ?: 'document';

                $originalName = Str::limit(
                    $originalName,
                    250,
                    ''
                );

                $evidence =
                    new PropertySubmissionEvidenceFile();

                $evidence->forceFill([
                    'property_submission_id' => $locked->id,
                    'uploaded_by' => $reviewer->id,
                    'uploaded_by_name' => $reviewer->name,
                    'document_type' => $data['document_type'],
                    'title' => $data['title'],
                    'original_name' => $originalName,
                    'storage_disk' => 'local',
                    'file_path' => $storedPath,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'sha256' => $sha256,
                ])->save();

                return $evidence;
            });
        } catch (\Throwable $exception) {
            // Never leave an orphan file after failed DB insertion.
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Private evidence uploaded successfully.',
            'evidence' => $this->evidenceData($evidence),
        ], 201);
    }

    /**
     * Download privately, after verifying submission ownership
     * and the file's stored SHA-256 checksum.
     */
    public function download(
        Request $request,
        PropertySubmission $submission,
        PropertySubmissionEvidenceFile $evidence
    ): StreamedResponse {
        $this->authorizeReviewer($request);

        abort_unless(
            $evidence->property_submission_id === $submission->id,
            404
        );

        abort_unless($evidence->storage_disk === 'local', 404);

        $disk = Storage::disk('local');

        abort_unless(
            $disk->exists($evidence->file_path),
            404
        );

        $actualHash = hash_file(
            'sha256',
            $disk->path($evidence->file_path)
        );

        abort_unless(
            is_string($actualHash)
                && hash_equals(
                    $evidence->sha256,
                    $actualHash
                ),
            409,
            'Evidence integrity verification failed.'
        );

        $extension = strtolower(
            pathinfo(
                $evidence->file_path,
                PATHINFO_EXTENSION
            )
        );

        abort_unless(
            in_array(
                $extension,
                ['pdf', 'jpg', 'jpeg', 'png'],
                true
            ),
            409
        );

        return $disk->download(
            $evidence->file_path,
            "evidence-{$evidence->reference}.{$extension}",
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
