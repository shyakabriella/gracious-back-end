<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PublicPropertyInquiryController extends Controller
{
    public function store(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required_without:email',
                'nullable',
                'string',
                'max:30',
            ],
            'email' => [
                'required_without:phone',
                'nullable',
                'email',
                'max:255',
            ],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $property = Property::query()
            ->where('slug', $slug)
            ->where('verification_status', 'verified')
            ->where('availability_status', 'available')
            ->where('is_published', true)
            ->firstOrFail();

        $lead = DB::transaction(function () use ($data, $property) {
            $customer = Customer::create([
                'reference' => 'PENDING-' . Str::uuid(),
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'customer_type' => $property->listing_type === 'sale'
                    ? 'buyer'
                    : 'tenant',
                'status' => 'active',
                'created_by' => null,
            ]);

            $customer->update([
                'reference' => 'CUS-' . str_pad(
                    (string) $customer->id,
                    6,
                    '0',
                    STR_PAD_LEFT
                ),
            ]);

            $lead = Lead::create([
                'reference' => 'PENDING-' . Str::uuid(),
                'customer_id' => $customer->id,
                'property_id' => $property->id,
                'lead_type' => $property->listing_type === 'sale'
                    ? 'buy'
                    : 'rent',
                'source' => 'website',
                'status' => 'new',
                'priority' => 'medium',
                'subject' => 'Property inquiry: ' . $property->reference,
                'message' => $data['message'],
                'assigned_agent_id' => null,
                'created_by' => null,
            ]);

            $lead->update([
                'reference' => 'LEAD-' . str_pad(
                    (string) $lead->id,
                    6,
                    '0',
                    STR_PAD_LEFT
                ),
            ]);

            return $lead;
        });

        return response()->json([
            'message' => 'Your inquiry has been received successfully.',
            'inquiry_reference' => $lead->reference,
        ], 201);
    }
}
