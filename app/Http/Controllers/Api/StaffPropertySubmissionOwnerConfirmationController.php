<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertySubmission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffPropertySubmissionOwnerConfirmationController extends Controller
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

    /**
     * Record a manual independent owner confirmation.
     * This endpoint does not automatically contact the owner.
     */
    public function store(
        Request $request,
        PropertySubmission $submission
    ): JsonResponse {
        $reviewer = $this->authorizeReviewer($request);

        $isConfirmed = fn (): bool =>
            $request->input('outcome') === 'confirmed';

        $needsPhone = fn (): bool =>
            $request->input('contact_method') === 'phone_call';

        $data = $request->validate([
            'outcome' => [
                'required',
                Rule::in(['confirmed', 'denied', 'revoked']),
            ],
            'contact_method' => [
                'required',
                Rule::in([
                    'phone_call',
                    'in_person',
                    'administrative_review',
                ]),
            ],
            'contact_source' => [
                'required',
                Rule::in([
                    'independent_official_record',
                    'existing_verified_owner_record',
                    'in_person_identity_document',
                    'prior_confirmation_record',
                ]),
            ],
            'confirmed_owner_name' => [
                Rule::requiredIf($isConfirmed),
                Rule::prohibitedIf(
                    fn (): bool => !$isConfirmed()
                ),
                'nullable',
                'string',
                'min:2',
                'max:255',
            ],
            'contact_phone' => [
                Rule::requiredIf($needsPhone),
                Rule::prohibitedIf(
                    fn (): bool => !$needsPhone()
                ),
                'nullable',
                'string',
                'max:30',
                'regex:/^\+?[0-9\s().-]{9,30}$/',
            ],
            'confirmed_purpose' => [
                Rule::requiredIf($isConfirmed),
                Rule::prohibitedIf(
                    fn (): bool => !$isConfirmed()
                ),
                Rule::in(['sale', 'rent', 'management']),
            ],
            'owner_identity_confirmed' => [
                Rule::requiredIf($isConfirmed),
                Rule::prohibitedIf(
                    fn (): bool => !$isConfirmed()
                ),
                'accepted_if:outcome,confirmed',
            ],
            'service_consent_confirmed' => [
                Rule::requiredIf($isConfirmed),
                Rule::prohibitedIf(
                    fn (): bool => !$isConfirmed()
                ),
                'accepted_if:outcome,confirmed',
            ],
            'notes' => [
                'required',
                'string',
                'min:20',
                'max:2000',
            ],

            // Never accept server-owned fields from users.
            'status' => ['prohibited'],
            'reviewer_id' => ['prohibited'],
            'reviewer_name' => ['prohibited'],
            'contact_fingerprint' => ['prohibited'],
            'contact_phone_last4' => ['prohibited'],
            'created_at' => ['prohibited'],
            'converted_property_id' => ['prohibited'],
            'is_published' => ['prohibited'],
        ]);

        /*
         * Revocation must refer to a previous confirmation.
         * Positive and denied decisions require actual contact.
         */
        if ($data['outcome'] === 'revoked') {
            if (
                $data['contact_method'] !== 'administrative_review'
                || $data['contact_source']
                    !== 'prior_confirmation_record'
            ) {
                throw ValidationException::withMessages([
                    'contact_method' => [
                        'Revocation must reference the previous confirmation.',
                    ],
                ]);
            }
        } else {
            $validContact = match ($data['contact_method']) {
                'phone_call' => in_array(
                    $data['contact_source'],
                    [
                        'independent_official_record',
                        'existing_verified_owner_record',
                    ],
                    true
                ),
                'in_person' =>
                    $data['contact_source']
                        === 'in_person_identity_document',
                default => false,
            };

            if (!$validContact) {
                throw ValidationException::withMessages([
                    'contact_source' => [
                        'Independent source does not match the contact method.',
                    ],
                ]);
            }
        }

        /*
         * Normalize phone numbers without storing
         * the complete number in the database.
         */
        $fingerprint = null;
        $phoneLast4 = null;

        if ($data['contact_method'] === 'phone_call') {
            $digits = preg_replace(
                '/\D+/',
                '',
                $data['contact_phone']
            );

            if (
                preg_match('/^07[2389][0-9]{7}$/', $digits) === 1
            ) {
                $digits = '250' . substr($digits, 1);
            }

            if (
                preg_match('/^2507[2389][0-9]{7}$/', $digits)
                    !== 1
            ) {
                throw ValidationException::withMessages([
                    'contact_phone' => [
                        'Enter a valid Rwanda mobile phone number.',
                    ],
                ]);
            }

            $key = (string) config('app.key');

            if (str_starts_with($key, 'base64:')) {
                $key = base64_decode(
                    substr($key, 7),
                    true
                );
            }

            if (!is_string($key) || strlen($key) < 32) {
                abort(
                    503,
                    'Owner confirmation fingerprint key unavailable.'
                );
            }

            $fingerprint = hash_hmac(
                'sha256',
                'gracious-owner-confirmation:v1:' . $digits,
                $key
            );

            $phoneLast4 = substr($digits, -4);
        }

        $result = DB::transaction(function () use (
            $submission,
            $reviewer,
            $data,
            $fingerprint,
            $phoneLast4
        ): array {
            $locked = PropertySubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            if (
                $locked->status !== 'under_review'
                || $locked->converted_property_id !== null
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only unconverted submissions under review are eligible.',
                    ],
                ]);
            }

            if (
                $locked->submitter_role !== 'authorized_agent'
                || trim((string) $locked->owner_name) === ''
            ) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Owner confirmation requires an authorized-agent submission.',
                    ],
                ]);
            }

            $table =
                'property_submission_owner_confirmation_events';

            $latest = DB::table($table)
                ->where(
                    'property_submission_id',
                    $locked->id
                )
                ->orderByDesc('id')
                ->first();

            if (
                $latest !== null
                && $latest->outcome === $data['outcome']
            ) {
                throw ValidationException::withMessages([
                    'outcome' => [
                        'This confirmation outcome is already current.',
                    ],
                ]);
            }

            if (
                $data['outcome'] === 'revoked'
                && (
                    $latest === null
                    || $latest->outcome !== 'confirmed'
                )
            ) {
                throw ValidationException::withMessages([
                    'outcome' => [
                        'Only a current confirmation can be revoked.',
                    ],
                ]);
            }

            if (
                $data['outcome'] === 'denied'
                && $latest !== null
                && $latest->outcome === 'confirmed'
            ) {
                throw ValidationException::withMessages([
                    'outcome' => [
                        'Revoke the existing confirmation first.',
                    ],
                ]);
            }

            if ($data['outcome'] === 'confirmed') {
                $expectedName = Str::lower(
                    Str::squish($locked->owner_name)
                );

                $actualName = Str::lower(
                    Str::squish($data['confirmed_owner_name'])
                );

                if ($expectedName !== $actualName) {
                    throw ValidationException::withMessages([
                        'confirmed_owner_name' => [
                            'Confirmed owner must match the named property owner.',
                        ],
                    ]);
                }

                if (
                    $data['confirmed_purpose']
                    !== $locked->submission_purpose
                ) {
                    throw ValidationException::withMessages([
                        'confirmed_purpose' => [
                            'Owner consent must match the requested service.',
                        ],
                    ]);
                }

                $verificationTable =
                    'property_submission_verification_checks';

                foreach (
                    ['contact', 'ownership', 'authorization']
                    as $type
                ) {
                    $current = DB::table($verificationTable)
                        ->where(
                            'property_submission_id',
                            $locked->id
                        )
                        ->where('check_type', $type)
                        ->orderByDesc('id')
                        ->first();

                    if (
                        $current === null
                        || $current->outcome !== 'verified'
                    ) {
                        throw ValidationException::withMessages([
                            'outcome' => [
                                "A current verified {$type} check is required.",
                            ],
                        ]);
                    }
                }

                if ($locked->contact_verified_at === null) {
                    throw ValidationException::withMessages([
                        'outcome' => [
                            'Contact verification must still be valid.',
                        ],
                    ]);
                }
            }

            $eventId = DB::table($table)->insertGetId([
                'property_submission_id' => $locked->id,
                'reviewer_id' => $reviewer->id,
                'reviewer_name' => $reviewer->name,
                'outcome' => $data['outcome'],
                'contact_method' => $data['contact_method'],
                'contact_source' => $data['contact_source'],
                'confirmed_owner_name' =>
                    $data['confirmed_owner_name'] ?? null,
                'contact_fingerprint' => $fingerprint,
                'contact_phone_last4' => $phoneLast4,
                'confirmed_purpose' =>
                    $data['confirmed_purpose'] ?? null,
                'owner_identity_confirmed' =>
                    $data['outcome'] === 'confirmed',
                'service_consent_confirmed' =>
                    $data['outcome'] === 'confirmed',
                'notes' => $data['notes'],
                'created_at' => now(),
            ]);

            return [
                'event_id' => $eventId,
                'reference' => $locked->reference,
                'outcome' => $data['outcome'],
                'phone_last4' => $phoneLast4,
            ];
        });

        return response()->json([
            'message' => 'Owner confirmation decision recorded.',
            'submission_reference' => $result['reference'],
            'outcome' => $result['outcome'],
            'confirmation_event_id' => $result['event_id'],
            'contact_phone_last4' => $result['phone_last4'],
        ], 201);
    }

    /**
     * Restricted, paginated confirmation history.
     * Full contact numbers and fingerprints are excluded.
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
            'property_submission_owner_confirmation_events'
        )
            ->where(
                'property_submission_id',
                $submission->id
            )
            ->select([
                'id',
                'outcome',
                'contact_method',
                'contact_source',
                'confirmed_owner_name',
                'contact_phone_last4',
                'confirmed_purpose',
                'owner_identity_confirmed',
                'service_consent_confirmed',
                'notes',
                'reviewer_id',
                'reviewer_name',
                'created_at',
            ])
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json($history);
    }
}
