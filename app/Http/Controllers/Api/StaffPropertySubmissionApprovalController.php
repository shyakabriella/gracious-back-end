<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyCategory;
use App\Models\PropertySubmission;
use App\Models\PropertySubmissionEvidenceFile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffPropertySubmissionApprovalController extends Controller
{
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

    private function rejectApproval(
        string $field,
        string $message
    ): never {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }

    private function latestVerification(
        PropertySubmission $submission,
        string $type
    ): object {
        $check = DB::table(
            'property_submission_verification_checks'
        )
            ->where('property_submission_id', $submission->id)
            ->where('check_type', $type)
            ->orderByDesc('id')
            ->first();

        if ($check === null || $check->outcome !== 'verified') {
            $this->rejectApproval(
                'verification',
                "A current verified {$type} check is required."
            );
        }

        return $check;
    }

    /**
     * Recheck the actual stored document at approval time.
     * A previous positive staff decision is insufficient if
     * the document was subsequently withdrawn or altered.
     */
    private function requireValidEvidence(
        PropertySubmission $submission,
        object $verification,
        string $expectedType
    ): void {
        $reference = $verification->evidence_reference ?? null;

        if (!is_string($reference) || $reference === '') {
            $this->rejectApproval(
                'evidence_reference',
                'Verification evidence reference is missing.'
            );
        }

        $evidence = PropertySubmissionEvidenceFile::query()
            ->where('property_submission_id', $submission->id)
            ->where('reference', $reference)
            ->lockForUpdate()
            ->first();

        if (
            $evidence === null
            || $evidence->withdrawn_at !== null
            || $evidence->document_type !== $expectedType
            || $evidence->storage_disk !== 'local'
        ) {
            $this->rejectApproval(
                'evidence_reference',
                "An active {$expectedType} document is required."
            );
        }

        $prefix =
            "property-submissions/{$submission->id}/evidence/";

        $path = $evidence->file_path;

        $filename = is_string($path)
            && str_starts_with($path, $prefix)
                ? substr($path, strlen($prefix))
                : '';

        if (
            preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._-]*\.(?:pdf|jpg|jpeg|png)\z/i',
                $filename
            ) !== 1
        ) {
            $this->rejectApproval(
                'evidence_reference',
                'Evidence has an invalid private storage path.'
            );
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($path)) {
            $this->rejectApproval(
                'evidence_reference',
                'Required verification evidence file is missing.'
            );
        }

        $actualHash = @hash_file(
            'sha256',
            $disk->path($path)
        );

        if (
            !is_string($actualHash)
            || !is_string($evidence->sha256)
            || !hash_equals($evidence->sha256, $actualHash)
        ) {
            $this->rejectApproval(
                'evidence_reference',
                'Verification evidence integrity check failed.'
            );
        }
    }

    private function requireOwnerConfirmation(
        PropertySubmission $submission
    ): void {
        $confirmation = DB::table(
            'property_submission_owner_confirmation_events'
        )
            ->where('property_submission_id', $submission->id)
            ->orderByDesc('id')
            ->first();

        if (
            $confirmation === null
            || $confirmation->outcome !== 'confirmed'
            || !(bool) $confirmation->owner_identity_confirmed
            || !(bool) $confirmation->service_consent_confirmed
        ) {
            $this->rejectApproval(
                'owner_confirmation',
                'Current independent owner confirmation is required.'
            );
        }

        $expectedOwner = Str::lower(
            Str::squish((string) $submission->owner_name)
        );

        $confirmedOwner = Str::lower(
            Str::squish(
                (string) $confirmation->confirmed_owner_name
            )
        );

        if (
            $expectedOwner === ''
            || $expectedOwner !== $confirmedOwner
            || $confirmation->confirmed_purpose
                !== $submission->submission_purpose
        ) {
            $this->rejectApproval(
                'owner_confirmation',
                'Owner identity or service consent no longer matches.'
            );
        }

        $method = $confirmation->contact_method;
        $source = $confirmation->contact_source;

        $validContact = match ($method) {
            'phone_call' => in_array(
                $source,
                [
                    'independent_official_record',
                    'existing_verified_owner_record',
                ],
                true
            )
                && is_string($confirmation->contact_fingerprint)
                && strlen($confirmation->contact_fingerprint) === 64,
            'in_person' =>
                $source === 'in_person_identity_document',
            default => false,
        };

        if (!$validContact) {
            $this->rejectApproval(
                'owner_confirmation',
                'Independent owner contact evidence is invalid.'
            );
        }
    }

    /**
     * Approve a reviewed submission without creating,
     * verifying, or publishing an official property.
     */
    public function approve(
        Request $request,
        PropertySubmission $submission
    ): JsonResponse {
        $reviewer = $this->authorizeReviewer($request);

        $data = $request->validate([
            'reason' => [
                'required',
                'string',
                'min:10',
                'max:2000',
            ],

            'status' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'review_notes' => ['prohibited'],
            'converted_property_id' => ['prohibited'],
            'converted_at' => ['prohibited'],
            'is_published' => ['prohibited'],
            'verification_status' => ['prohibited'],
            'property_id' => ['prohibited'],
        ]);

        $result = DB::transaction(function () use (
            $submission,
            $reviewer,
            $data
        ): array {
            $locked = PropertySubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            if (
                $locked->status !== 'under_review'
                || $locked->converted_property_id !== null
                || $locked->converted_at !== null
            ) {
                $this->rejectApproval(
                    'status',
                    'Only unconverted submissions under review can be approved.'
                );
            }

            if (
                $locked->terms_accepted_at === null
                || $locked->authorization_confirmed_at === null
            ) {
                $this->rejectApproval(
                    'status',
                    'Applicant declarations are incomplete.'
                );
            }

            if (
                !in_array(
                    $locked->submitter_role,
                    [
                        'owner',
                        'landlord',
                        'developer',
                        'authorized_agent',
                    ],
                    true
                )
            ) {
                $this->rejectApproval(
                    'status',
                    'Unsupported applicant role.'
                );
            }

            if (
                trim((string) $locked->property_title) === ''
                || trim((string) $locked->submitter_phone) === ''
                || !in_array(
                    $locked->submission_purpose,
                    ['sale', 'rent', 'management'],
                    true
                )
            ) {
                $this->rejectApproval(
                    'status',
                    'Submission details are incomplete.'
                );
            }

            if (
                $locked->category_id === null
                || !PropertyCategory::query()
                    ->whereKey($locked->category_id)
                    ->where('is_active', true)
                    ->exists()
            ) {
                $this->rejectApproval(
                    'status',
                    'An active property category is required.'
                );
            }

            if (
                in_array(
                    $locked->submission_purpose,
                    ['sale', 'rent'],
                    true
                )
                && (
                    $locked->asking_price === null
                    || (float) $locked->asking_price <= 0
                )
            ) {
                $this->rejectApproval(
                    'status',
                    'A valid asking price is required.'
                );
            }

            $previousApproval = DB::table(
                'property_submission_review_events'
            )
                ->where(
                    'property_submission_id',
                    $locked->id
                )
                ->where('action', 'approved')
                ->exists();

            if ($previousApproval) {
                $this->rejectApproval(
                    'status',
                    'This submission already has an approval record.'
                );
            }

            $this->latestVerification($locked, 'contact');

            if ($locked->contact_verified_at === null) {
                $this->rejectApproval(
                    'verification',
                    'Current contact verification is required.'
                );
            }

            $ownership = $this->latestVerification(
                $locked,
                'ownership'
            );

            $this->requireValidEvidence(
                $locked,
                $ownership,
                'ownership_title'
            );

            if ($locked->submitter_role === 'authorized_agent') {
                if (trim((string) $locked->owner_name) === '') {
                    $this->rejectApproval(
                        'authorization',
                        'A named property owner is required.'
                    );
                }

                $authorization = $this->latestVerification(
                    $locked,
                    'authorization'
                );

                $this->requireValidEvidence(
                    $locked,
                    $authorization,
                    'owner_authorization'
                );

                $this->requireOwnerConfirmation($locked);
            }

            $timestamp = now();

            $locked->forceFill([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $timestamp,
                'review_notes' => $data['reason'],
            ])->save();

            $eventId = DB::table(
                'property_submission_review_events'
            )->insertGetId([
                'property_submission_id' => $locked->id,
                'reviewer_id' => $reviewer->id,
                'reviewer_name' => $reviewer->name,
                'action' => 'approved',
                'from_status' => 'under_review',
                'to_status' => 'approved',
                'reason' => $data['reason'],
                'created_at' => $timestamp,
            ]);

            return [
                'reference' => $locked->reference,
                'event_id' => $eventId,
            ];
        });

        return response()->json([
            'message' => 'Property submission approved.',
            'submission_reference' => $result['reference'],
            'status' => 'approved',
            'review_event_id' => $result['event_id'],
            'converted_property_id' => null,
            'is_published' => false,
        ]);
    }
}
