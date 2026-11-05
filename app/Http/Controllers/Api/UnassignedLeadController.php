<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnassignedLeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasAnyRole(['super_admin', 'admin'])
                && $user->can('leads.assign'),
            403
        );

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'in:website,phone,walk_in,referral,social_media,staff'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Lead::query()
            ->whereNull('assigned_agent_id')
            ->whereNotIn('status', ['won', 'lost'])
            ->with([
                'customer:id,reference,name,phone,email',
                'property:id,reference,title,slug',
            ]);

        if (!empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customer) use ($search) {
                        $customer->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json(
            $query->orderBy('created_at')
                ->orderBy('id')
                ->paginate($filters['per_page'] ?? 15)
        );
    }
}
