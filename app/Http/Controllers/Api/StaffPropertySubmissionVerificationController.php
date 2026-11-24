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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffPropertySubmissionVerificationController extends Controller
{
    /**
     * Only authorized administrators may view or
     * record verification information.
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
     * Record a manual contact or ownership check.
     *
     * The evidence reference is an internal tracking
     * reference, not independently verified proof.
     *
     * All decisions are append-only history events.
     */
    public function store(
        Request $request,
        PropertySubmission $submission
    ): JsonResponse {
        $reviewer = $this->authorizeReviewer($request);

        $requiresEvidence = fn (): bool =>
            in_array(
                $request->input('check_type'),
                ['ownership', 'authorization'],
                true
            )
            && $request->input('outcome') === 'verified';

        $data = $request->validate([
            'check_type' => [
                'required',
                Rule::in(['contact', 'ownership', 'authorization']),
            ],
            'outcome' => [
                'required',
                Rule::in(['verified', 'failed', 'revoked']),
            ],
            'method' => [
                'required',
                'string',
                Rule::in([
                    'phone_call',
                    'in_person',
                    'land_title_review',
                    'owner_authorization_review',
                ]),
            ],
            'evidence_reference' => [
                Rule::requiredIf($requiresEvidence),
                Rule::prohibitedIf(
                    fn (): bool => !$requiresEvidence()
                ),
                'nullable',
                'string',
                'min:9',
                'max:120',
                'regex:/^EV-[A-Za-z0-9][A-Za-z0-9-]{5,115}$/',
            ],
            'notes' => [
                'required',
                'string',
                'min:10',
                'max:2000',
            ],

            // Prevent applicant or staff from injecting
            // internal workflow and audit attributes.
            'status' => ['prohibited'],
            'performed_by' => ['prohibited'],
            'performed_by_name' => ['prohibited'],
            'contact_verified_at' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'converted_property_id' => ['prohibited'],
            'is_published' => ['prohibited'],
        ]);

        $methods = [
            'contact' => [
                'phone_call',
                'in_person',
            ],
            'ownership' => [
                'land_title_review',
            ],
            'authorization' => [
                'owner_authorization_review',
            ],
        ];

        if (!in_array(
            $data['method'],
            $methods[$data['check_type']],
            true
        )) {
            throw ValidationException::withMessages([
                'method' => [
                    'Method is not valid for this verification type.',
                ],
            ]);
        }

        $result = DB::transaction(function () use (
            $submission,
            $reviewer,
            $data
        ) {
            $locked = PropertySubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            if ($locked->status !== 'under_review') {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only submissions under review can be verified.',
                    ],
                ]);
            }

            if ($locked->converted_property_id !== null) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Converted submissions cannot be verified again.',
                    ],
                ]);
            }

            /*
             * Representative authorization applies only to
             * submissions made by authorized agents who
             * identify the owner.
             */
            if (
                $data['check_type'] === 'authorization'
                && (
                    $locked->submitter_role !== 'authorized_agent'
                    || trim((string) $locked->owner_name) === ''
                )
            ) {
                throw ValidationException::withMessages([
                    'check_type' => [
                        'Authorization checks require an authorized-agent submission with a named owner.',
                    ],
                ]);
            }

            $table = 'property_submission_verification_checks';

            $latest = DB::table($table)
                ->where(
                    'property_submission_id',
                    $locked->id
                )
                ->where(
                    'check_type',
                    $data['check_type']
                )
                ->orderByDesc('id')
                ->first();

            if (
                $latest !== null
                && $latest->outcome === $data['outcome']
            ) {
                throw ValidationException::withMessages([
                    'outcome' => [
                        'This verification result is already current.',
                    ],
                ]);
            }

            // A verification can only be revoked if
            // the most recent outcome is verified.
            if (
                $data['outcome'] === 'revoked'
                && (
                    $latest === null
                    || $latest->outcome !== 'verified'
                )
            ) {
                throw ValidationException::withMessages([
                    'outcome' => [
                        'Only a current verification can be revoked.',
                    ],
                ]);
            }

            // A currently verified result must be
            // formally revoked, not silently replaced.
            if (
                $data['outcome'] === 'failed'
                && $latest !== null
                && $latest->outcome === 'verified'
            ) {
                throw ValidationException::withMessages([
                    'outcome' => [
                        'Revoke the existing verification first.',
                    ],
                ]);
            }

            // Positive ownership and authorization require verified contact.
            // Check the actual latest contact audit event,
            // not merely a timestamp on the submission.
            if (
                in_array(
                    $data['check_type'],
                    ['ownership', 'authorization'],
                    true
                )
                && $data['outcome'] === 'verified'
            ) {
                $contact = DB::table($table)
                    ->where(
                        'property_submission_id',
                        $locked->id
                    )
                    ->where('check_type', 'contact')
                    ->orderByDesc('id')
                    ->first();

                if (
                    $contact === null
                    || $contact->outcome !== 'verified'
                    || $locked->contact_verified_at === null
                ) {
                    throw ValidationException::withMessages([
                        'check_type' => [
                            'Contact must be verified before ownership.',
                        ],
                    ]);
                }
            }

            /*
             * Ownership verification requires genuine stored
             * evidence associated with this application.
             *
             * An authorization letter alone is not proof
             * of ownership.
             */
            if (
                in_array(
                    $data['check_type'],
                    ['ownership', 'authorization'],
                    true
                )
                && $data['outcome'] === 'verified'
            ) {
                $isAuthorization =
                    $data['check_type'] === 'authorization';

                /*
                 * Owner authorization cannot be positively
                 * verified unless ownership is currently
                 * verified as a separate decision.
                 */
                if ($isAuthorization) {
                    $ownership = DB::table($table)
                        ->where(
                            'property_submission_id',
                            $locked->id
                        )
                        ->where('check_type', 'ownership')
                        ->orderByDesc('id')
                        ->first();

                    if (
                        $ownership === null
                        || $ownership->outcome !== 'verified'
                    ) {
                        throw ValidationException::withMessages([
                            'check_type' => [
                                'Ownership must be verified before representative authorization.',
                            ],
                        ]);
                    }
                }

                $expectedMethod = $isAuthorization
                    ? 'owner_authorization_review'
                    : 'land_title_review';

                $expectedDocumentType = $isAuthorization
                    ? 'owner_authorization'
                    : 'ownership_title';

                if ($data['method'] !== $expectedMethod) {
                    throw ValidationException::withMessages([
                        'method' => [
                            'Verification method does not match the required document review.',
                        ],
                    ]);
                }

                $evidence = PropertySubmissionEvidenceFile::query()
                    ->where(
                        'property_submission_id',
                        $locked->id
                    )
                    ->where(
                        'reference',
                        $data['evidence_reference']
                    )
                    ->lockForUpdate()
                    ->first();

                if (
                    $evidence === null
                    || $evidence->withdrawn_at !== null
                    || $evidence->storage_disk !== 'local'
                    || $evidence->document_type !== $expectedDocumentType
                ) {
                    throw ValidationException::withMessages([
                        'evidence_reference' => [
                            'An active matching verification document is required.',
                        ],
                    ]);
                }

                $prefix = "property-submissions/{$locked->id}/evidence/";

                $path = $evidence->file_path;

                $filename = str_starts_with($path, $prefix)
                    ? substr($path, strlen($prefix))
                    : '';

                if (
                    preg_match(
                        '/\A[A-Za-z0-9][A-Za-z0-9._-]*\.(?:pdf|jpg|jpeg|png)\z/i',
                        $filename
                    ) !== 1
                ) {
                    throw ValidationException::withMessages([
                        'evidence_reference' => [
                            'Evidence has an invalid private storage path.',
                        ],
                    ]);
                }

                $disk = Storage::disk('local');

                if (!$disk->exists($path)) {
                    throw ValidationException::withMessages([
                        'evidence_reference' => [
                            'Ownership evidence file is missing.',
                        ],
                    ]);
                }

                $actualHash = hash_file(
                    'sha256',
                    $disk->path($path)
                );

                if (
                    !is_string($actualHash)
                    || !hash_equals(
                        $evidence->sha256,
                        $actualHash
                    )
                ) {
                    throw ValidationException::withMessages([
                        'evidence_reference' => [
                            'Ownership evidence integrity check failed.',
                        ],
                    ]);
                }
            }

            $timestamp = now();

            $eventId = DB::table($table)->insertGetId([
                'property_submission_id' => $locked->id,
                'performed_by' => $reviewer->id,
                'performed_by_name' => $reviewer->name,
                'check_type' => $data['check_type'],
                'outcome' => $data['outcome'],
                'method' => $data['method'],
                'evidence_reference' =>
                    $data['evidence_reference'] ?? null,
                'notes' => $data['notes'],
                'created_at' => $timestamp,
            ]);

            if ($data['check_type'] === 'contact') {
                $locked->forceFill([
                    'contact_verified_at' =>
                        $data['outcome'] === 'verified'
                            ? $timestamp
                            : null,
                ])->save();
            }

            return [
                'reference' => $locked->reference,
                'check_type' => $data['check_type'],
                'outcome' => $data['outcome'],
                'event_id' => $eventId,
                'contact_verified_at' =>
                    $locked->contact_verified_at,
            ];
        });

        return response()->json([
            'message' => 'Verification decision recorded.',
            'submission_reference' => $result['reference'],
            'check_type' => $result['check_type'],
            'outcome' => $result['outcome'],
            'verification_event_id' => $result['event_id'],
            'contact_verified_at' =>
                $result['contact_verified_at'],
        ], 201);
    }

    /**
     * Read-only audit history.
     *
     * Requires the same authorization as writing.
     * Never publicly expose this information.
     */
    public function history(
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

        $history = DB::table(
            'property_submission_verification_checks'
        )
            ->where(
                'property_submission_id',
                $submission->id
            )
            ->select([
                'id',
                'check_type',
                'outcome',
                'method',
                'evidence_reference',
                'notes',
                'performed_by',
                'performed_by_name',
                'created_at',
            ])
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json($history);
    }
}
