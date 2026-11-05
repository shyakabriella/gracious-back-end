<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyRental;
use App\Models\User;
use App\Services\PropertyRentStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyRentStatementController extends Controller
{
    private function canView(
        User $user,
        PropertyRental $rental
    ): bool {
        if (!$user->can('rentals.view')) {
            return false;
        }

        return $user->hasAnyRole([
            'super_admin',
            'admin',
            'property_manager',
            'accountant',
        ]) || (
            $user->hasRole('agent')
            && (int) $rental->agent_id === (int) $user->id
        );
    }

    public function show(
        Request $request,
        PropertyRental $rental,
        PropertyRentStatementService $statements
    ): JsonResponse {
        abort_unless(
            $this->canView($request->user(), $rental),
            404
        );

        $data = $request->validate([
            'as_of' => [
                'sometimes',
                'date_format:Y-m-d',
            ],
        ]);

        return response()->json([
            'statement' => $statements->build(
                $rental,
                $data['as_of'] ?? null
            ),
        ]);
    }
}
