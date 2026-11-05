<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(
        Request $request,
        DashboardService $dashboard
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user->can('dashboard.view'),
            403,
            'You do not have permission to view the dashboard.'
        );

        return response()->json([
            'message' => 'Dashboard loaded successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'roles' => $user->getRoleNames(),
            ],
            'statistics' => $dashboard->summary($user),
        ]);
    }
}
