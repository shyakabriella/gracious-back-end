<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertySubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffPropertySubmissionController extends Controller
{
    /**
     * Only authorized administrators may access
     * personal information in property submissions.
     */
    private function authorizeReviewer(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null
                && $user->hasAnyRole(['super_admin', 'admin'])
                && $user->can('properties.verify'),
            403
        );
    }

    /**
     * Staff inbox: search, filter, and paginate.
     *
     * No writes or workflow changes occur here.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeReviewer($request);

        $filters = $request->validate([
            'status' => [
                'nullable',
                Rule::in([
                    'pending_review',
                    'under_review',
                    'approved',
                    'rejected',
                ]),
            ],
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = PropertySubmission::query()
            ->with(['category:id,name'])
            ->select([
                'id',
                'reference',
                'status',
                'source',
                'submitter_name',
                'submitter_phone',
                'submitter_role',
                'submission_purpose',
                'property_title',
                'category_id',
                'asking_price',
                'currency',
                'province',
                'district',
                'sector',
                'reviewed_at',
                'created_at',
            ]);

        if (!empty($filters['status'])) {
            $query->where(
                'status',
                $filters['status']
            );
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);

            if ($search !== '') {
                $query->where(function ($scope) use ($search) {
                    $scope->where(
                        'reference',
                        'like',
                        "%{$search}%"
                    )->orWhere(
                        'submitter_name',
                        'like',
                        "%{$search}%"
                    )->orWhere(
                        'submitter_phone',
                        'like',
                        "%{$search}%"
                    )->orWhere(
                        'property_title',
                        'like',
                        "%{$search}%"
                    );
                });
            }
        }

        return response()->json(
            $query
                ->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 15)
        );
    }

    /**
     * Detailed staff-only submission view.
     *
     * Do not expose this endpoint publicly.
     */
    public function show(
        Request $request,
        PropertySubmission $submission
    ): JsonResponse {
        $this->authorizeReviewer($request);

        $submission->load([
            'category:id,name,slug',
            'reviewer:id,name',
            'convertedProperty:id,reference,title',
        ]);

        // Explicit fields prevent unintended future
        // database columns from being exposed.
        $details = $submission->only([
            'id',
            'reference',
            'status',
            'source',

            'submitter_name',
            'submitter_phone',
            'submitter_email',
            'submitter_role',

            'owner_name',
            'owner_phone',

            'submission_purpose',
            'property_title',
            'property_description',
            'category_id',

            'asking_price',
            'currency',
            'is_negotiable',

            'province',
            'district',
            'sector',
            'cell',
            'village',
            'address',
            'latitude',
            'longitude',

            'land_size_sqm',
            'building_size_sqm',
            'bedrooms',
            'bathrooms',
            'parking_spaces',
            'floors',
            'is_furnished',

            'terms_accepted_at',
            'authorization_confirmed_at',
            'contact_verified_at',

            'reviewed_by',
            'reviewed_at',
            'review_notes',

            'converted_property_id',
            'converted_at',

            'created_at',
            'updated_at',
        ]);

        $details['category'] = $submission->category
            ? $submission->category->only([
                'id',
                'name',
                'slug',
            ])
            : null;

        $details['reviewer'] = $submission->reviewer
            ? $submission->reviewer->only([
                'id',
                'name',
            ])
            : null;

        $details['converted_property'] =
            $submission->convertedProperty
                ? $submission->convertedProperty->only([
                    'id',
                    'reference',
                    'title',
                ])
                : null;

        return response()->json([
            'submission' => $details,
        ]);
    }
}
