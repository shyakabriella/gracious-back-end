<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertySubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PublicPropertySubmissionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /*
         * This endpoint is intended for unauthenticated
         * property owners and authorized representatives.
         *
         * It must never create an official Property or
         * publish a listing.
         *
         * Rate limiting will be attached to the API route.
         */

        $data = $request->validate([

            // Applicant identity
            'submitter_name' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],

            'submitter_phone' => [
                'required',
                'string',
                'min:7',
                'max:30',
                'regex:/^\+?[0-9][0-9\s().-]{5,28}$/',
            ],

            'submitter_email' => [
                'nullable',
                'email:rfc',
                'max:255',
            ],

            'submitter_role' => [
                'required',
                Rule::in([
                    'owner',
                    'landlord',
                    'developer',
                    'authorized_agent',
                ]),
            ],

            // Owner information, if represented by an agent
            'owner_name' => [
                'required_if:submitter_role,authorized_agent',
                'nullable',
                'string',
                'max:255',
            ],

            'owner_phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            // Requested Gracious service
            'submission_purpose' => [
                'required',
                Rule::in([
                    'sale',
                    'rent',
                    'management',
                ]),
            ],

            // Property information
            'property_title' => [
                'required',
                'string',
                'min:5',
                'max:255',
            ],

            'property_description' => [
                'required',
                'string',
                'min:20',
                'max:10000',
            ],

            'category_id' => [
                'required',
                'integer',
                Rule::exists(
                    'property_categories',
                    'id'
                )->where('is_active', true),
            ],

            // Pricing
            'asking_price' => [
                'required_unless:submission_purpose,management',
                'nullable',
                'numeric',
                'gt:0',
                'decimal:0,2',
            ],

            'currency' => [
                'sometimes',
                Rule::in(['RWF', 'USD']),
            ],

            'is_negotiable' => [
                'sometimes',
                'boolean',
            ],

            // Rwanda property location
            'province' => [
                'required',
                'string',
                'max:100',
            ],

            'district' => [
                'required',
                'string',
                'max:100',
            ],

            'sector' => [
                'required',
                'string',
                'max:100',
            ],

            'cell' => [
                'nullable',
                'string',
                'max:100',
            ],

            'village' => [
                'nullable',
                'string',
                'max:100',
            ],

            'address' => [
                'nullable',
                'string',
                'max:255',
            ],

            'latitude' => [
                'nullable',
                'required_with:longitude',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'nullable',
                'required_with:latitude',
                'numeric',
                'between:-180,180',
            ],

            // Property size
            'land_size_sqm' => [
                'nullable',
                'numeric',
                'gt:0',
                'decimal:0,2',
                'max:9999999999.99',
            ],

            'building_size_sqm' => [
                'nullable',
                'numeric',
                'gt:0',
                'decimal:0,2',
                'max:9999999999.99',
            ],

            // Property facilities
            'bedrooms' => [
                'nullable',
                'integer',
                'between:0,1000',
            ],

            'bathrooms' => [
                'nullable',
                'integer',
                'between:0,1000',
            ],

            'parking_spaces' => [
                'nullable',
                'integer',
                'between:0,1000',
            ],

            'floors' => [
                'nullable',
                'integer',
                'between:0,1000',
            ],

            'is_furnished' => [
                'sometimes',
                'boolean',
            ],

            // Explicit declarations required
            'terms_accepted' => [
                'required',
                'accepted',
            ],

            'authorization_confirmed' => [
                'required',
                'accepted',
            ],

            // Sensitive internal fields must not be set
            // by unauthenticated applicants.
            'reference' => ['prohibited'],
            'status' => ['prohibited'],
            'source' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'review_notes' => ['prohibited'],
            'contact_verified_at' => ['prohibited'],
            'converted_property_id' => ['prohibited'],
            'converted_at' => ['prohibited'],
            'created_by' => ['prohibited'],
            'is_published' => ['prohibited'],

            // Uploads will be implemented through a
            // separate validated private-media workflow.
            'photos' => ['prohibited'],
            'videos' => ['prohibited'],
            'documents' => ['prohibited'],
        ]);

        /*
         * Never persist incoming declaration booleans
         * as though they were review or approval fields.
         */
        $submissionFields = Arr::except($data, [
            'terms_accepted',
            'authorization_confirmed',
        ]);

        $uploadToken = bin2hex(random_bytes(32));
        $uploadExpiresAt = now()->addHours(24);

        $submission = DB::transaction(
            function () use (
                $submissionFields,
                $uploadToken,
                $uploadExpiresAt
            ) {

                $submission = PropertySubmission::create(
                    $submissionFields
                );

                /*
                 * These timestamps are set by the server
                 * only after request validation succeeds.
                 */
                $submission->forceFill([
                    'terms_accepted_at' => now(),
                    'authorization_confirmed_at' => now(),
                ])->save();

                // Store only the token hash, never the raw token.
                DB::table(
                    'property_submission_upload_authorizations'
                )->insert([
                    'property_submission_id' => $submission->id,
                    'token_hash' => hash('sha256', $uploadToken),
                    'expires_at' => $uploadExpiresAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $submission;
            }
        );

        /*
         * Do not return personal information,
         * internal staff notes, or documents.
         */
        return response()->json([
            'message' =>
                'Your property submission has been received. '
                . 'Gracious Investment will review it.',
            'submission_reference' => $submission->reference,
            'submission_id' => $submission->id,
            'status' => 'pending_review',
            // Returned once. Never included in staff/list responses.
            'upload_token' => $uploadToken,
            'upload_expires_at' =>
                $uploadExpiresAt->toIso8601String(),
        ], 201);
    }
}
