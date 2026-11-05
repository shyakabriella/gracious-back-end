<?php

namespace App\Services\Dashboard;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PropertyStatisticsService
{
    public function summary(User $user): array
    {
        if (!$user->can('properties.view')) {
            return [
                'available' => false,
                'reason' => 'Access restricted',
            ];
        }

        $query = Property::query();

        if (!$user->hasAnyRole(['super_admin', 'admin'])) {
            $query->where(function (Builder $query) use ($user) {
                $query->where('created_by', $user->id)
                    ->orWhere('agent_id', $user->id);
            });
        }

        $counts = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN verification_status = 'draft' THEN 1 ELSE 0 END) as draft")
            ->selectRaw("SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) as verified")
            ->selectRaw("SUM(CASE WHEN verification_status = 'rejected' THEN 1 ELSE 0 END) as rejected")
            ->selectRaw("SUM(CASE WHEN availability_status = 'available' THEN 1 ELSE 0 END) as available_properties")
            ->selectRaw("SUM(CASE WHEN availability_status = 'reserved' THEN 1 ELSE 0 END) as reserved")
            ->selectRaw("SUM(CASE WHEN availability_status = 'sold' THEN 1 ELSE 0 END) as sold")
            ->selectRaw("SUM(CASE WHEN availability_status = 'rented' THEN 1 ELSE 0 END) as rented")
            ->selectRaw("SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published")
            ->selectRaw("SUM(CASE WHEN listing_type = 'sale' THEN 1 ELSE 0 END) as for_sale")
            ->selectRaw("SUM(CASE WHEN listing_type = 'rent' THEN 1 ELSE 0 END) as for_rent")
            ->first();

        return [
            'available' => true,
            'total' => (int) $counts->total,
            'draft' => (int) $counts->draft,
            'pending' => (int) $counts->pending,
            'verified' => (int) $counts->verified,
            'rejected' => (int) $counts->rejected,
            'available_properties' => (int) $counts->available_properties,
            'reserved' => (int) $counts->reserved,
            'sold' => (int) $counts->sold,
            'rented' => (int) $counts->rented,
            'published' => (int) $counts->published,
            'for_sale' => (int) $counts->for_sale,
            'for_rent' => (int) $counts->for_rent,
        ];
    }
}
