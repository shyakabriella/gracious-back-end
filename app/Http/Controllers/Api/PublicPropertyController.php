<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyCategory;
use App\Models\PropertyImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicPropertyController extends Controller
{
    private function visibleProperties()
    {
        return Property::query()
            ->where('verification_status', 'verified')
            ->where('availability_status', 'available')
            ->where('is_published', true);
    }

    public function filters(): JsonResponse
    {
        $locations = [];

        foreach (['province', 'district', 'sector'] as $field) {
            $locations[$field === 'province' ? 'provinces' : $field . 's'] =
                $this->visibleProperties()
                    ->whereNotNull($field)
                    ->where($field, '<>', '')
                    ->distinct()
                    ->orderBy($field)
                    ->pluck($field)
                    ->values();
        }

        return response()->json([
            'categories' => PropertyCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),

            'locations' => $locations,

            'listing_types' => ['sale', 'rent'],

            'currencies' => $this->visibleProperties()
                ->whereNotNull('currency')
                ->distinct()
                ->orderBy('currency')
                ->pluck('currency')
                ->values(),
        ]);
    }

    public function home(): JsonResponse
    {
        $visible = $this->visibleProperties();

        $statistics = [
            'total' => (clone $visible)->count(),
            'for_sale' => (clone $visible)
                ->where('listing_type', 'sale')
                ->count(),
            'for_rent' => (clone $visible)
                ->where('listing_type', 'rent')
                ->count(),
        ];

        $categoryCounts = $this->visibleProperties()
            ->selectRaw('category_id, COUNT(*) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $categories = PropertyCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'property_count' => (int) (
                    $categoryCounts[$category->id] ?? 0
                ),
            ]);

        $listing = function (?string $type = null) {
            return $this->visibleProperties()
                ->with([
                    'category:id,name,slug',
                    'images' => fn ($query) => $query
                        ->orderByDesc('is_cover')
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                ])
                ->when($type, fn ($query) =>
                    $query->where('listing_type', $type)
                )
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(6)
                ->get()
                ->map(fn (Property $property) =>
                    $this->publicData($property)
                )
                ->values();
        };

        return response()->json([
            'statistics' => $statistics,
            'categories' => $categories,
            'latest_properties' => $listing(),
            'featured_properties' => $this->visibleProperties()
                ->where('is_featured', true)
                ->with([
                    'category:id,name,slug',
                    'images' => fn ($query) => $query
                        ->orderByDesc('is_cover')
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                ])
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(6)
                ->get()
                ->map(fn (Property $property) =>
                    $this->publicData($property)
                )
                ->values(),
            'sale_properties' => $listing('sale'),
            'rental_properties' => $listing('rent'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'listing_type' => ['nullable', Rule::in(['sale', 'rent'])],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'min_bedrooms' => ['nullable', 'integer', 'min:0'],
            'min_bathrooms' => ['nullable', 'integer', 'min:0'],
            'is_furnished' => ['nullable', 'boolean'],
            'sort' => [
                'nullable',
                Rule::in(['latest', 'oldest', 'price_asc', 'price_desc']),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $this->visibleProperties()
            ->with([
                'category:id,name,slug',
                'images' => fn ($query) => $query
                    ->orderByDesc('is_cover')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ]);

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('district', 'like', "%{$search}%")
                    ->orWhere('sector', 'like', "%{$search}%")
                    ->orWhere('province', 'like', "%{$search}%");
            });
        }

        foreach (['category_id', 'listing_type', 'province', 'district', 'sector'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['min_price'])) {
            $query->where('asking_price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('asking_price', '<=', $filters['max_price']);
        }

        if (isset($filters['min_bedrooms'])) {
            $query->where('bedrooms', '>=', $filters['min_bedrooms']);
        }

        if (isset($filters['min_bathrooms'])) {
            $query->where('bathrooms', '>=', $filters['min_bathrooms']);
        }

        if (isset($filters['is_furnished'])) {
            $query->where('is_furnished', $filters['is_furnished']);
        }

        switch ($filters['sort'] ?? 'latest') {
            case 'oldest':
                $query->orderBy('published_at')->orderBy('id');
                break;

            case 'price_asc':
                $query->orderBy('asking_price')->orderBy('id');
                break;

            case 'price_desc':
                $query->orderByDesc('asking_price')->orderByDesc('id');
                break;

            default:
                $query->orderByDesc('published_at')->orderByDesc('id');
        }

        $properties = $query->paginate($filters['per_page'] ?? 12)
            ->withQueryString();

        $properties->through(fn (Property $property) =>
            $this->publicData($property)
        );

        return response()->json($properties);
    }

    public function show(string $slug): JsonResponse
    {
        $property = $this->visibleProperties()
            ->with([
                'category:id,name,slug',
                'images' => fn ($query) => $query
                    ->orderByDesc('is_cover')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'property' => $this->publicData($property),
        ]);
    }

    private function imageData(Property $property, PropertyImage $image): array
    {
        return [
            'id' => $image->id,
            'url' => url(
                "/api/public/properties/{$property->id}/images/{$image->id}"
            ),
            'is_cover' => $image->is_cover,
            'sort_order' => $image->sort_order,
        ];
    }

    private function publicData(Property $property): array
    {
        $images = $property->images->map(
            fn (PropertyImage $image) => $this->imageData($property, $image)
        )->values();

        return $property->only([
            'reference',
            'title',
            'slug',
            'description',
            'listing_type',
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
            'published_at',
        ]) + [
            'category' => $property->category?->only([
                'id', 'name', 'slug',
            ]),
            'cover_image' => $images->firstWhere('is_cover', true)
                ?? $images->first(),
            'images' => $images->all(),
        ];
    }
}
