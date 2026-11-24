<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertySubmission;
use App\Models\PropertySubmissionMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublicPropertySubmissionMediaController extends Controller
{
    private function invalid(
        string $field,
        string $message
    ): never {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }

    private function authorizeUpload(
        Request $request,
        PropertySubmission $submission
    ): PropertySubmission {
        $token = $request->header(
            'X-Submission-Upload-Token'
        );

        if (
            !is_string($token)
            || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
        ) {
            abort(403, 'Invalid upload authorization.');
        }

        $locked = PropertySubmission::query()
            ->lockForUpdate()
            ->findOrFail($submission->id);

        if (
            $locked->status !== 'pending_review'
            || $locked->converted_property_id !== null
        ) {
            abort(403, 'Uploads are closed for this submission.');
        }

        $authorization = DB::table(
            'property_submission_upload_authorizations'
        )
            ->where('property_submission_id', $locked->id)
            ->lockForUpdate()
            ->first();

        if (
            $authorization === null
            || $authorization->revoked_at !== null
            || Carbon::parse($authorization->expires_at)
                ->lessThanOrEqualTo(now())
            || !is_string($authorization->token_hash)
            || !hash_equals(
                $authorization->token_hash,
                hash('sha256', $token)
            )
        ) {
            abort(403, 'Upload authorization is expired or invalid.');
        }

        return $locked;
    }

    /**
     * Check type, extension and actual file signature.
     *
     * Media is still untrusted and must be reviewed by staff.
     */
    private function inspectFile(
        mixed $file,
        string $kind
    ): array {
        if (
            !$file instanceof
                \Illuminate\Http\UploadedFile
            || !$file->isValid()
        ) {
            $this->invalid('file', 'A valid file is required.');
        }

        $path = $file->getRealPath();

        $size = $file->getSize();

        if (
            !is_string($path)
            || !is_file($path)
            || !is_int($size)
            || $size <= 0
        ) {
            $this->invalid('file', 'The uploaded file is invalid.');
        }

        $extension = Str::lower(
            $file->getClientOriginalExtension()
        );

        $mime = $file->getMimeType();

        $limit = match ($kind) {
            'photo', 'document' => 10 * 1024 * 1024,
            'video' => 100 * 1024 * 1024,
            default => 0,
        };

        if ($size > $limit) {
            $this->invalid(
                'file',
                'The file exceeds the allowed upload size.'
            );
        }

        $extensions = match ($kind) {
            'photo' => [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
            ],
            'video' => [
                'mp4' => 'video/mp4',
                'webm' => 'video/webm',
            ],
            'document' => [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
            ],
        };

        if (
            !isset($extensions[$extension])
            || $mime !== $extensions[$extension]
        ) {
            $this->invalid(
                'file',
                'The file extension and detected format do not match.'
            );
        }

        if (
            $kind === 'photo'
            || (
                $kind === 'document'
                && $extension !== 'pdf'
            )
        ) {
            $image = @getimagesize($path);

            if (
                $image === false
                || ($image['mime'] ?? null) !== $mime
                || ($image[0] ?? 0) <= 0
                || ($image[1] ?? 0) <= 0
            ) {
                $this->invalid(
                    'file',
                    'The image content is invalid.'
                );
            }
        }

        if ($kind === 'document' && $extension === 'pdf') {
            $head = file_get_contents(
                $path,
                false,
                null,
                0,
                5
            );

            $handle = fopen($path, 'rb');

            if ($handle === false) {
                $this->invalid('file', 'Cannot inspect PDF.');
            }

            try {
                fseek($handle, -min($size, 2048), SEEK_END);
                $tail = stream_get_contents($handle);
            } finally {
                fclose($handle);
            }

            if (
                $head !== '%PDF-'
                || !is_string($tail)
                || !str_contains($tail, '%%EOF')
            ) {
                $this->invalid(
                    'file',
                    'The PDF document structure is invalid.'
                );
            }
        }

        if ($kind === 'video') {
            $head = file_get_contents(
                $path,
                false,
                null,
                0,
                4096
            );

            if (!is_string($head)) {
                $this->invalid(
                    'file',
                    'Cannot inspect video.'
                );
            }

            $signatureValid = match ($extension) {
                'mp4' => strlen($head) >= 12
                    && substr($head, 4, 4) === 'ftyp',
                'webm' => str_starts_with(
                    $head,
                    "\x1A\x45\xDF\xA3"
                )
                    && stripos($head, 'webm') !== false,
                default => false,
            };

            if (!$signatureValid) {
                $this->invalid(
                    'file',
                    'Invalid video container signature.'
                );
            }
        }

        $sha256 = hash_file('sha256', $path);

        if (!is_string($sha256)) {
            $this->invalid(
                'file',
                'Unable to verify file integrity.'
            );
        }

        $originalName = basename(
            str_replace(
                '\\',
                '/',
                $file->getClientOriginalName()
            )
        );

        $originalName = trim(
            preg_replace(
                '/[\x00-\x1F\x7F]/',
                '',
                $originalName
            ) ?? ''
        );

        if ($originalName === '') {
            $originalName = "submission-file.{$extension}";
        }

        return [
            'extension' => $extension,
            'mime_type' => $mime,
            'file_size' => $size,
            'sha256' => $sha256,
            'original_name' => Str::limit(
                $originalName,
                255,
                ''
            ),
        ];
    }

    public function store(
        Request $request,
        PropertySubmission $submission
    ): JsonResponse {
        $storedPath = null;

        try {
            $result = DB::transaction(
                function () use (
                    $request,
                    $submission,
                    &$storedPath
                ): array {
                    // Check upload authorization before accepting
                    // any document or media metadata.
                    $locked = $this->authorizeUpload(
                        $request,
                        $submission
                    );

                    $data = $request->validate([
                        'kind' => [
                            'required',
                            Rule::in([
                                'photo',
                                'video',
                                'document',
                            ]),
                        ],
                        'document_type' => [
                            Rule::requiredIf(
                                fn () =>
                                    $request->input('kind')
                                        === 'document'
                            ),
                            Rule::prohibitedIf(
                                fn () =>
                                    $request->input('kind')
                                        !== 'document'
                            ),
                            Rule::in([
                                'ownership_title',
                                'owner_authorization',
                                'other',
                            ]),
                        ],
                        'file' => [
                            'required',
                            'file',
                            'max:102400',
                        ],
                        'review_status' => ['prohibited'],
                        'is_primary' => ['prohibited'],
                        'sort_order' => ['prohibited'],
                        'storage_disk' => ['prohibited'],
                        'file_path' => ['prohibited'],
                        'sha256' => ['prohibited'],
                        'reviewed_by' => ['prohibited'],
                    ]);

                    $kind = $data['kind'];

                    $limits = [
                        'photo' => 10,
                        'video' => 2,
                        'document' => 5,
                    ];

                    // The locked submission serializes
                    // concurrent public uploads.
                    $count = PropertySubmissionMedia::query()
                        ->where(
                            'property_submission_id',
                            $locked->id
                        )
                        ->where('kind', $kind)
                        ->count();

                    if ($count >= $limits[$kind]) {
                        $this->invalid(
                            'file',
                            "Maximum {$limits[$kind]} {$kind} uploads allowed."
                        );
                    }

                    $file = $request->file('file');

                    $details = $this->inspectFile(
                        $file,
                        $kind
                    );

                    $directory =
                        "property-submissions/{$locked->id}/media";

                    $filename = Str::uuid()->toString()
                        . '.' . $details['extension'];

                    $storedPath = Storage::disk('local')
                        ->putFileAs(
                            $directory,
                            $file,
                            $filename
                        );

                    if (
                        !is_string($storedPath)
                        || $storedPath === ''
                    ) {
                        $storedPath = null;

                        $this->invalid(
                            'file',
                            'Unable to save private upload.'
                        );
                    }

                    $media = new PropertySubmissionMedia();

                    $media->forceFill([
                        'property_submission_id' =>
                            $locked->id,
                        'kind' => $kind,
                        'document_type' =>
                            $data['document_type'] ?? null,
                        'original_name' =>
                            $details['original_name'],
                        'storage_disk' => 'local',
                        'file_path' => $storedPath,
                        'mime_type' =>
                            $details['mime_type'],
                        'file_size' =>
                            $details['file_size'],
                        'sha256' =>
                            $details['sha256'],
                        'sort_order' => $count + 1,
                        'is_primary' =>
                            $kind === 'photo'
                            && $count === 0,
                        'review_status' => 'pending',
                    ])->save();

                    return [
                        'id' => $media->id,
                        'kind' => $media->kind,
                        'document_type' =>
                            $media->document_type,
                        'original_name' =>
                            $media->original_name,
                        'file_size' => $media->file_size,
                        'review_status' =>
                            $media->review_status,
                        'is_primary' =>
                            $media->is_primary,
                    ];
                },
                3
            );
        } catch (Throwable $exception) {
            // A failed database transaction must not
            // leave a newly stored private file behind.
            if (is_string($storedPath)) {
                Storage::disk('local')->delete(
                    $storedPath
                );
            }

            throw $exception;
        }

        return response()->json([
            'message' =>
                'File received for private staff review.',
            'media' => $result,
        ], 201);
    }
}
