<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PropertyController extends Controller
{
    private function manager(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    private function visibleProperties(User $user)
    {
        return Property::query()
            ->when(!$this->manager($user), function ($query) use ($user) {
                $query->where(function ($scope) use ($user) {
                    $scope->where('created_by', $user->id)
                        ->orWhere('agent_id', $user->id);
                });
            });
    }

    private function authorizeProperty(
        Request $request,
        Property $property,
        string $permission
    ): void {
        abort_unless($request->user()->can($permission), 403);

        abort_unless(
            $this->manager($request->user()) ||
            $property->created_by === $request->user()->id,
            403
        );
    }

    private function rules(bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => [
                $required,
                'integer',
                Rule::exists('property_categories', 'id')
                    ->where('is_active', true),
            ],
            'listing_type' => [$required, Rule::in(['sale', 'rent'])],
            'asking_price' => [$required, 'numeric', 'gt:0'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['RWF', 'USD'])],
            'is_negotiable' => ['sometimes', 'boolean'],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:100'],
            'cell' => ['nullable', 'string', 'max:100'],
            'village' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'land_size_sqm' => ['nullable', 'numeric', 'gt:0'],
            'building_size_sqm' => ['nullable', 'numeric', 'gt:0'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:999'],
            'bathrooms' => ['nullable', 'integer', 'min:0', 'max:999'],
            'parking_spaces' => ['nullable', 'integer', 'min:0', 'max:999'],
            'floors' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_furnished' => ['sometimes', 'boolean'],
        ];
    }

    public function categories(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('properties.view'), 403);

        return response()->json([
            'categories' => PropertyCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('properties.view'), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:property_categories,id'],
            'listing_type' => ['nullable', Rule::in(['sale', 'rent'])],
            'availability_status' => ['nullable', Rule::in([
                'available', 'reserved', 'sold', 'rented', 'archived',
            ])],
            'district' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->visibleProperties($request->user())
            ->with(['category:id,name,slug', 'agent:id,name']);

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        foreach (['category_id', 'listing_type', 'availability_status', 'district'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        if (isset($filters['min_price'])) {
            $query->where('asking_price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('asking_price', '<=', $filters['max_price']);
        }

        return response()->json(
            $query->latest()->paginate($filters['per_page'] ?? 15)
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('properties.create'), 403);

        $data = $request->validate($this->rules());

        $property = DB::transaction(function () use ($data, $request) {
            $property = Property::create([
                ...$data,
                'reference' => 'PENDING-' . Str::uuid(),
                'slug' => 'pending-' . Str::uuid(),
                'created_by' => $request->user()->id,
                'agent_id' => $request->user()->hasRole('agent')
                    ? $request->user()->id
                    : null,
            ]);

            $property->update([
                'reference' => 'GI-' . str_pad(
                    (string) $property->id, 6, '0', STR_PAD_LEFT
                ),
                'slug' => Str::slug($property->title) . '-' . $property->id,
            ]);

            return $property;
        });

        return response()->json([
            'message' => 'Property created successfully',
            'property' => $property->load(['category', 'agent']),
        ], 201);
    }

    public function show(Request $request, Property $property): JsonResponse
    {
        abort_unless($request->user()->can('properties.view'), 403);

        abort_unless(
            $this->manager($request->user()) ||
            $property->created_by === $request->user()->id ||
            $property->agent_id === $request->user()->id,
            403
        );

        return response()->json([
            'property' => $property->load(['category', 'agent', 'creator']),
        ]);
    }

    public function update(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, 'properties.update');

        $data = $request->validate($this->rules(false));

        $property->update($data);

        if (array_key_exists('title', $data)) {
            $property->update([
                'slug' => Str::slug($property->title) . '-' . $property->id,
            ]);
        }

        // An edited, previously verified property must be reviewed again.
        if ($property->verification_status === 'verified' && $data !== []) {
            $property->forceFill([
                'verification_status' => 'pending',
                'verified_at' => null,
                'is_published' => false,
                'published_at' => null,
            ])->save();
        }

        return response()->json([
            'message' => 'Property updated successfully',
            'property' => $property->fresh()->load('category'),
        ]);
    }

    public function destroy(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, 'properties.delete');

        $property->forceFill([
            'is_published' => false,
            'published_at' => null,
        ])->save();

        $property->delete();

        return response()->json([
            'message' => 'Property archived successfully',
        ]);
    }
}
