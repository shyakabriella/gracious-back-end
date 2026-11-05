<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function summary(User $user): array
    {
        $canViewStaff = $user->can('users.view');

        return [
            'staff' => $canViewStaff
                ? $this->staffStatistics()
                : $this->unavailable('Access restricted'),

            'properties' => app(PropertyStatisticsService::class)
                ->summary($user),

            'sales' => $this->unavailable(
                'Sales module not implemented'
            ),

            'rentals' => $this->unavailable(
                'Rentals module not implemented'
            ),

            'leads' => $this->unavailable(
                'Leads module not implemented'
            ),

            'finance' => $user->can('finance.view')
                ? $this->unavailable(
                    'Finance module not implemented'
                )
                : $this->unavailable('Access restricted'),
        ];
    }

    private function staffStatistics(): array
    {
        $statuses = User::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $roles = DB::table('roles')
            ->leftJoin('model_has_roles', function ($join) {
                $join->on('roles.id', '=', 'model_has_roles.role_id')
                    ->where(
                        'model_has_roles.model_type',
                        (new User())->getMorphClass()
                    );
            })
            ->where('roles.guard_name', 'web')
            ->select(
                'roles.name',
                DB::raw('COUNT(model_has_roles.role_id) as total')
            )
            ->groupBy('roles.id', 'roles.name')
            ->orderBy('roles.name')
            ->pluck('total', 'name');

        return [
            'available' => true,
            'total' => (int) $statuses->sum(),
            'active' => (int) ($statuses['active'] ?? 0),
            'suspended' => (int) ($statuses['suspended'] ?? 0),
            'by_role' => $roles->map(
                fn ($count) => (int) $count
            )->all(),
        ];
    }

    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
        ];
    }
}
