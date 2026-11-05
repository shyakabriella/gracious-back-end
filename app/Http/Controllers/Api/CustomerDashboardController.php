<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole('customer'),
            403,
            'This dashboard is for customer accounts only.'
        );

        $profile = $user->customerProfile()->first();

        if (! $profile) {
            return response()->json([
                'profile' => null,
                'inquiries' => [
                    'total' => 0,
                    'by_status' => (object) [],
                    'recent' => [],
                ],
            ]);
        }

        $leads = $profile->leads();

        $statuses = $profile->leads()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count) => (int) $count);

        $recent = $profile->leads()
            ->select([
                'id',
                'reference',
                'customer_id',
                'property_id',
                'lead_type',
                'status',
                'subject',
                'created_at',
            ])
            ->with('property:id,reference,title,slug')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($lead) => [
                'id' => $lead->id,
                'reference' => $lead->reference,
                'lead_type' => $lead->lead_type,
                'status' => $lead->status,
                'subject' => $lead->subject,
                'created_at' => $lead->created_at,
                'property' => $lead->property
                    ? [
                        'reference' => $lead->property->reference,
                        'title' => $lead->property->title,
                        'slug' => $lead->property->slug,
                    ]
                    : null,
            ]);

        return response()->json([
            'profile' => [
                'reference' => $profile->reference,
                'name' => $profile->name,
                'phone' => $profile->phone,
                'email' => $profile->email,
                'customer_type' => $profile->customer_type,
                'preferred_location' => $profile->preferred_location,
            ],
            'inquiries' => [
                'total' => $leads->count(),
                'by_status' => $statuses,
                'recent' => $recent,
            ],
        ]);
    }
}
