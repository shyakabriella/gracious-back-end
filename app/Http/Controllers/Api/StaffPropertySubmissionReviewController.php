<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertySubmission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffPropertySubmissionReviewController extends Controller
{
    /**
     * Only an authorized Gracious administrator
     * can change submission review status.
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
     * Start reviewing a pending application.
     *
     * pending_review -> under_review
     */
    public function startReview(
        Request $request,
        PropertySubmission $submission
    ): JsonResponse {
        $reviewer = $this->authorizeReviewer($request);

        $data = $request->validate([
            'reason' => [
                'nullable',
                'string',
                'min:5',
                'max:2000',
            ],
            'status' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'converted_property_id' => ['prohibited'],
            'is_published' => ['prohibited'],
        ]);

        $result = DB::transaction(function () use (
            $submission,
            $reviewer,
            $data
        ) {
            $locked = PropertySubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->getKey());

            if ($locked->status !== 'pending_review') {
                throw ValidationException::withMessages([
                    'status' => [
                        'Only pending submissions can enter review.',
                    ],
                ]);
            }

            $previousStatus = $locked->status;
            $nextStatus = 'under_review';
            $timestamp = now();

            $locked->forceFill([
                'status' => $nextStatus,
            ])->save();

            $eventId = DB::table(
                'property_submission_review_events'
            )->insertGetId([
                'property_submission_id' => $locked->id,
                'reviewer_id' => $reviewer->id,
                'reviewer_name' => $reviewer->name,
                'action' => 'review_started',
                'from_status' => $previousStatus,
                'to_status' => $nextStatus,
                'reason' => $data['reason'] ?? null,
                'created_at' => $timestamp,
            ]);

            return [
                'reference' => $locked->reference,
                'status' => $nextStatus,
                'event_id' => $eventId,
            ];
        });

        return response()->json([
            'message' => 'Property submission review started.',
            'submission_reference' => $result['reference'],
            'status' => $result['status'],
            'review_event_id' => $result['event_id'],
        ]);
    }

    /**
     * Reject an application currently under review.
     *
     * under_review -> rejected
     *
     * Rejection requires a written reason.
     */
    public function reject(
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
            'converted_property_id' => ['prohibited'],
            'is_published' => ['prohibited'],
        ]);

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
                        'Only submissions under review can be rejected.',
                    ],
                ]);
            }

            if ($locked->converted_property_id !== null) {
                throw ValidationException::withMessages([
                    'status' => [
                        'Converted submissions cannot be rejected.',
                    ],
                ]);
            }

            $previousStatus = $locked->status;
            $nextStatus = 'rejected';
            $timestamp = now();

            $locked->forceFill([
                'status' => $nextStatus,
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
                'action' => 'rejected',
                'from_status' => $previousStatus,
                'to_status' => $nextStatus,
                'reason' => $data['reason'],
                'created_at' => $timestamp,
            ]);

            return [
                'reference' => $locked->reference,
                'status' => $nextStatus,
                'event_id' => $eventId,
            ];
        });

        return response()->json([
            'message' => 'Property submission rejected.',
            'submission_reference' => $result['reference'],
            'status' => $result['status'],
            'review_event_id' => $result['event_id'],
        ]);
    }
}
