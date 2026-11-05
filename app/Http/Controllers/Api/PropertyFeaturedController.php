<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PropertyFeaturedController extends Controller
{
    public function update(
        Request $request,
        Property $property
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user->hasAnyRole(['super_admin', 'admin'])
                && $user->can('properties.publish'),
            403
        );

        $data = $request->validate([
            'is_featured' => ['required', 'boolean'],
        ]);

        $property = DB::transaction(function () use ($property, $data) {
            $property = Property::query()
                ->lockForUpdate()
                ->findOrFail($property->id);

            if ($data['is_featured'] && (
                $property->verification_status !== 'verified'
                || !$property->is_published
                || $property->availability_status !== 'available'
            )) {
                throw ValidationException::withMessages([
                    'is_featured' => [
                        'Only verified, published and available properties can be featured.',
                    ],
                ]);
            }

            $property->forceFill([
                'is_featured' => $data['is_featured'],
            ])->save();

            return $property;
        });

        return response()->json([
            'message' => 'Featured status updated successfully',
            'property_id' => $property->id,
            'is_featured' => (bool) $property->is_featured,
        ]);
    }
}
