<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PropertyWorkflowController extends Controller
{
    private function manager(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function canEdit(User $user, Property $property): bool
    {
        return $user->can('properties.update') &&
            ($this->manager($user) ||
                $property->created_by === $user->id);
    }

    public function submit(Request $request, Property $property): JsonResponse
    {
        abort_unless($this->canEdit($request->user(), $property), 403);

        if (!in_array($property->verification_status, ['draft', 'rejected'])) {
            throw ValidationException::withMessages([
                'verification_status' => ['This property cannot be submitted.'],
            ]);
        }

        $property->forceFill([
            'verification_status' => 'pending',
            'verified_at' => null,
            'is_published' => false,
            'published_at' => null,
        ])->save();

        return response()->json([
            'message' => 'Property submitted for verification',
            'property' => $property->fresh(),
        ]);
    }

    public function verify(Request $request, Property $property): JsonResponse
    {
        abort_unless(
            $this->manager($request->user()) &&
            $request->user()->can('properties.verify'),
            403
        );

        $data = $request->validate([
            'status' => ['required', Rule::in(['verified', 'rejected'])],
            'reason' => [
                Rule::requiredIf(fn () =>
                    $request->input('status') === 'rejected'
                ),
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $property = DB::transaction(function () use ($request, $property, $data) {
            $property = Property::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            if ($property->verification_status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending properties can be reviewed.'],
                ]);
            }

            $property->forceFill([
                'verification_status' => $data['status'],
                'verified_at' => $data['status'] === 'verified'
                    ? now()
                    : null,
                'is_published' => false,
                'published_at' => null,
            ])->save();

            $property->verificationHistory()->create([
                'reviewed_by' => $request->user()->id,
                'decision' => $data['status'],
                'reason' => $data['reason'] ?? null,
            ]);

            return $property;
        });

        return response()->json([
            'message' => 'Property verification updated',
            'property' => $property->fresh(),
        ]);
    }

    public function history(Request $request, Property $property): JsonResponse
    {
        abort_unless(
            $this->manager($request->user()) &&
            $request->user()->can('properties.verify'),
            403
        );

        return response()->json([
            'history' => $property->verificationHistory()
                ->with('reviewer:id,name')
                ->latest()
                ->get(),
        ]);
    }

    public function publish(Request $request, Property $property): JsonResponse
    {
        abort_unless(
            $request->user()->can('properties.publish'),
            403
        );

        $data = $request->validate([
            'is_published' => ['required', 'boolean'],
        ]);

        if ($data['is_published'] &&
            ($property->verification_status !== 'verified' ||
             $property->availability_status !== 'available')) {
            throw ValidationException::withMessages([
                'is_published' => [
                    'Only verified and available properties can be published.',
                ],
            ]);
        }

        $property->forceFill([
            'is_published' => $data['is_published'],
            'published_at' => $data['is_published'] ? now() : null,
        ])->save();

        return response()->json([
            'message' => 'Property publication updated',
            'property' => $property->fresh(),
        ]);
    }

    public function availability(Request $request, Property $property): JsonResponse
    {
        abort_unless($this->canEdit($request->user(), $property), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                'available', 'reserved', 'sold', 'rented', 'archived',
            ])],
        ]);

        if (in_array($data['status'], ['sold', 'rented', 'archived'], true)) {
            abort_unless($this->manager($request->user()), 403);
        }

        $property = \Illuminate\Support\Facades\DB::transaction(
            function () use ($property, $data) {
                $property = Property::query()
                    ->lockForUpdate()
                    ->findOrFail($property->id);

                $hasActiveLease = \App\Models\PropertyRental::query()
                    ->where('property_id', $property->id)
                    ->where('status', 'active')
                    ->exists();

                if ($hasActiveLease && $data['status'] !== 'rented') {
                    throw ValidationException::withMessages([
                        'status' => [
                            'This property has an active lease. End the lease first.',
                        ],
                    ]);
                }

                if (!$hasActiveLease && $data['status'] === 'rented') {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Activate a rental agreement to mark this property as rented.',
                        ],
                    ]);
                }

                $property->forceFill([
                    'availability_status' => $data['status'],
                    'is_published' => $data['status'] === 'available'
                        ? $property->is_published
                        : false,
                    'published_at' => $data['status'] === 'available'
                        ? $property->published_at
                        : null,
                ])->save();

                return $property;
            }
        );

        return response()->json([
            'message' => 'Property availability updated',
            'property' => $property->fresh(),
        ]);
    }

    public function assignAgent(Request $request, Property $property): JsonResponse
    {
        abort_unless(
            $this->manager($request->user()) &&
            $request->user()->can('properties.update'),
            403
        );

        $data = $request->validate([
            'agent_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $agent = User::findOrFail($data['agent_id']);

        if (!$agent->isActive() || !$agent->hasRole('agent')) {
            throw ValidationException::withMessages([
                'agent_id' => ['Select an active real estate agent.'],
            ]);
        }

        $property->update([
            'agent_id' => $agent->id,
        ]);

        return response()->json([
            'message' => 'Agent assigned successfully',
            'property' => $property->fresh()->load('agent:id,name,email'),
        ]);
    }
}
