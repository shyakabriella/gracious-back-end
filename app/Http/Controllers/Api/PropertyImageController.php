<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

class PropertyImageController extends Controller
{
    private function canView(Request $request, Property $property): bool
    {
        $user = $request->user();

        return $user->can('properties.view') && (
            $user->hasAnyRole(['super_admin', 'admin']) ||
            $property->created_by === $user->id ||
            $property->agent_id === $user->id
        );
    }

    private function canEdit(Request $request, Property $property): bool
    {
        $user = $request->user();

        return $user->can('properties.update') && (
            $user->hasAnyRole(['super_admin', 'admin']) ||
            $property->created_by === $user->id
        );
    }

    private function checkImage(Property $property, PropertyImage $image): void
    {
        abort_unless($image->property_id === $property->id, 404);
    }

    public function index(Request $request, Property $property)
    {
        abort_unless($this->canView($request, $property), 403);

        return response()->json([
            'images' => $property->images()
                ->get()
                ->map(fn ($image) => $this->imageData($image)),
        ]);
    }

    public function store(Request $request, Property $property)
    {
        abort_unless($this->canEdit($request, $property), 403);

        $request->validate([
            'image' => [
                'required',
                File::image()->max('5mb'),
            ],
        ]);

        $file = $request->file('image');

        $path = $file->store(
            "properties/{$property->id}/images",
            'local'
        );

        try {
            $image = DB::transaction(function () use ($request, $property, $file, $path) {
                $property = Property::query()
                    ->lockForUpdate()
                    ->findOrFail($property->id);

                $images = $property->images();

                $count = $images->count();

                if ($count >= 30) {
                    abort(422, 'Maximum 30 images per property.');
                }

                $image = $images->create([
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'sort_order' => (int) $images->max('sort_order') + 1,
                    'is_cover' => $count === 0,
                    'uploaded_by' => $request->user()->id,
                ]);

                // Changing property photos requires a new review.
                if ($property->verification_status === 'verified') {
                    $property->forceFill([
                        'verification_status' => 'pending',
                        'verified_at' => null,
                        'is_published' => false,
                        'published_at' => null,
                    ])->save();
                }

                return $image;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json([
            'message' => 'Property image uploaded successfully',
            'image' => $this->imageData($image),
        ], 201);
    }

    public function show(
        Request $request,
        Property $property,
        PropertyImage $image
    ) {
        $this->checkImage($property, $image);

        abort_unless($this->canView($request, $property), 403);

        return $this->serve($image);
    }

    public function publicShow(Property $property, PropertyImage $image)
    {
        $this->checkImage($property, $image);

        abort_unless(
            $property->verification_status === 'verified' &&
            $property->availability_status === 'available' &&
            $property->is_published,
            404
        );

        return $this->serve($image);
    }

    private function serve(PropertyImage $image)
    {
        $disk = Storage::disk('local');

        abort_unless($disk->exists($image->file_path), 404);

        return response()->file(
            $disk->path($image->file_path),
            [
                'Content-Type' => $image->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function cover(
        Request $request,
        Property $property,
        PropertyImage $image
    ) {
        abort_unless($this->canEdit($request, $property), 403);

        $this->checkImage($property, $image);

        DB::transaction(function () use ($property, $image) {
            $property = Property::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $property->images()->update(['is_cover' => false]);

            $image->update(['is_cover' => true]);
        });

        return response()->json([
            'message' => 'Cover image updated successfully',
        ]);
    }

    public function destroy(
        Request $request,
        Property $property,
        PropertyImage $image
    ) {
        abort_unless($this->canEdit($request, $property), 403);

        $this->checkImage($property, $image);

        DB::transaction(function () use ($property, $image) {
            $property = Property::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $wasCover = $image->is_cover;

            $image->delete();

            if ($wasCover) {
                $next = $property->images()
                    ->orderBy('sort_order')
                    ->first();

                $next?->update(['is_cover' => true]);
            }

            if ($property->verification_status === 'verified') {
                $property->forceFill([
                    'verification_status' => 'pending',
                    'verified_at' => null,
                    'is_published' => false,
                    'published_at' => null,
                ])->save();
            }
        });

        Storage::disk('local')->delete($image->file_path);

        return response()->json([
            'message' => 'Image deleted successfully',
        ]);
    }

    public function reorder(Request $request, Property $property)
    {
        abort_unless($this->canEdit($request, $property), 403);

        $data = $request->validate([
            'image_ids' => ['required', 'array', 'min:1', 'max:30'],
            'image_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        DB::transaction(function () use ($property, $data) {
            $lockedProperty = Property::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            $existingIds = $lockedProperty->images()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $requestedIds = array_map('intval', $data['image_ids']);

            $sortedRequested = $requestedIds;
            sort($sortedRequested);
            sort($existingIds);

            if ($sortedRequested !== $existingIds) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'image_ids' => [
                        'Provide every image belonging to this property exactly once.',
                    ],
                ]);
            }

            foreach ($requestedIds as $position => $imageId) {
                $lockedProperty->images()
                    ->whereKey($imageId)
                    ->update(['sort_order' => $position + 1]);
            }
        });

        return response()->json([
            'message' => 'Property images reordered successfully',
            'images' => $property->images()
                ->get()
                ->map(fn ($image) => $this->imageData($image)),
        ]);
    }

    private function imageData(PropertyImage $image): array
    {
        return [
            'id' => $image->id,
            'original_name' => $image->original_name,
            'mime_type' => $image->mime_type,
            'file_size' => $image->file_size,
            'is_cover' => $image->is_cover,
            'sort_order' => $image->sort_order,
        ];
    }
}
