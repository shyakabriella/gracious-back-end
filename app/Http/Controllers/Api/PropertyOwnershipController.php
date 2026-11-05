<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PropertyOwnershipController extends Controller
{
    private function isManager($user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function canViewProperty($user, Property $property): bool
    {
        return $this->isManager($user)
            || $property->created_by === $user->id
            || $property->agent_id === $user->id;
    }

    private function canManageProperty($user, Property $property): bool
    {
        return $this->isManager($user)
            || $property->created_by === $user->id;
    }

    private function canAccessOwner($user, PropertyOwner $owner): bool
    {
        if ($this->isManager($user)) {
            return true;
        }

        return $owner->created_by === $user->id
            || $owner->properties()
                ->where(function ($query) use ($user) {
                    $query->where('properties.created_by', $user->id)
                        ->orWhere('properties.agent_id', $user->id);
                })
                ->exists();
    }

    public function index(Request $request, Property $property): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->can('properties.view')
                && $user->can('owners.view'),
            403
        );

        abort_unless($this->canViewProperty($user, $property), 404);

        return response()->json([
            'owners' => $property->owners()
                ->orderBy('property_owners.name')
                ->get(),
        ]);
    }

    public function attach(Request $request, Property $property): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->can('properties.update')
                && $user->can('owners.view'),
            403
        );

        abort_unless($this->canManageProperty($user, $property), 403);

        $data = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:property_owners,id'],
        ]);

        $owner = PropertyOwner::findOrFail($data['owner_id']);

        abort_unless($this->canAccessOwner($user, $owner), 404);

        $attached = DB::transaction(function () use ($property, $owner) {
            Property::whereKey($property->id)->lockForUpdate()->firstOrFail();

            if ($property->owners()->whereKey($owner->id)->exists()) {
                return false;
            }

            $property->owners()->attach($owner->id);

            return true;
        });

        return response()->json([
            'message' => $attached
                ? 'Owner linked to property successfully'
                : 'Owner is already linked to this property',
            'owner_id' => $owner->id,
        ], $attached ? 201 : 200);
    }

    public function detach(
        Request $request,
        Property $property,
        PropertyOwner $owner
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user->can('properties.update')
                && $user->can('owners.view'),
            403
        );

        abort_unless($this->canManageProperty($user, $property), 403);

        abort_unless(
            $property->owners()->whereKey($owner->id)->exists(),
            404
        );

        $property->owners()->detach($owner->id);

        return response()->json([
            'message' => 'Owner removed from property successfully',
        ]);
    }
}
