<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PropertyOwnerController extends Controller
{
    private function isManager($user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function visibleOwners($user)
    {
        return PropertyOwner::query()
            ->when(!$this->isManager($user), function ($query) use ($user) {
                $query->where(function ($scope) use ($user) {
                    $scope->where('created_by', $user->id)
                        ->orWhereHas('properties', function ($properties) use ($user) {
                            $properties->where(function ($property) use ($user) {
                                $property->where('created_by', $user->id)
                                    ->orWhere('agent_id', $user->id);
                            });
                        });
                });
            });
    }

    private function canManage(Request $request, PropertyOwner $owner): bool
    {
        $user = $request->user();

        return $this->isManager($user) ||
            $owner->created_by === $user->id;
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'owner_type' => [
                $required,
                Rule::in(['individual', 'company']),
            ],
            'name' => [$required, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('owners.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'owner_type' => ['nullable', Rule::in(['individual', 'company'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visibleOwners($request->user());

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($scope) use ($search) {
                $scope->where('name', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        foreach (['owner_type', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return response()->json(
            $query->latest()->paginate($filters['per_page'] ?? 15)
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('owners.create'), 403);

        $data = $request->validate($this->rules(true));

        $owner = DB::transaction(function () use ($request, $data) {
            $owner = PropertyOwner::create([
                ...$data,
                'reference' => 'PENDING-' . Str::uuid(),
                'created_by' => $request->user()->id,
            ]);

            $owner->update([
                'reference' => 'OWN-' . str_pad(
                    (string) $owner->id,
                    6,
                    '0',
                    STR_PAD_LEFT
                ),
            ]);

            return $owner;
        });

        return response()->json([
            'message' => 'Property owner registered successfully',
            'owner' => $owner,
        ], 201);
    }

    public function properties(Request $request, PropertyOwner $owner): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->can('owners.view') && $user->can('properties.view'),
            403
        );

        abort_unless(
            $this->visibleOwners($user)->whereKey($owner->id)->exists(),
            404
        );

        $query = $owner->properties();

        if (!$this->isManager($user)) {
            $query->where(function ($scope) use ($user) {
                $scope->where('properties.created_by', $user->id)
                    ->orWhere('properties.agent_id', $user->id);
            });
        }

        $properties = $query
            ->orderBy('properties.id')
            ->get()
            ->map(fn ($property) => [
                'id' => $property->id,
                'reference' => $property->reference,
                'title' => $property->title,
                'slug' => $property->slug,
                'listing_type' => $property->listing_type,
                'asking_price' => $property->asking_price,
                'currency' => $property->currency,
                'verification_status' => $property->verification_status,
                'availability_status' => $property->availability_status,
                'is_published' => $property->is_published,
            ]);

        return response()->json([
            'owner_id' => $owner->id,
            'properties' => $properties->values(),
        ]);
    }

    public function show(Request $request, PropertyOwner $owner): JsonResponse
    {
        abort_unless($request->user()->can('owners.view'), 403);

        $visible = $this->visibleOwners($request->user())
            ->whereKey($owner->id)
            ->exists();

        abort_unless($visible, 404);

        return response()->json([
            'owner' => $owner,
        ]);
    }

    public function update(Request $request, PropertyOwner $owner): JsonResponse
    {
        abort_unless($request->user()->can('owners.update'), 403);
        abort_unless($this->canManage($request, $owner), 403);

        $owner->update($request->validate($this->rules(false)));

        return response()->json([
            'message' => 'Property owner updated successfully',
            'owner' => $owner->fresh(),
        ]);
    }

    public function destroy(Request $request, PropertyOwner $owner): JsonResponse
    {
        abort_unless($request->user()->can('owners.delete'), 403);
        abort_unless($this->canManage($request, $owner), 403);

        if ($owner->properties()->exists()) {
            return response()->json([
                'message' => 'Remove property ownership links before archiving this owner.',
            ], 422);
        }

        $owner->delete();

        return response()->json([
            'message' => 'Property owner archived successfully',
        ]);
    }
}
