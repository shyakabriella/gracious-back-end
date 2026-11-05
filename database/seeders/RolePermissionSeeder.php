<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        $groups = [
            'dashboard' => ['view'],
            'users' => ['view', 'create', 'update', 'suspend'],
            'roles' => ['view', 'manage'],
            'properties' => ['view', 'create', 'update', 'delete', 'publish', 'verify'],
            'owners' => ['view', 'create', 'update', 'delete'],
            'customers' => ['view', 'create', 'update'],
            'leads' => ['view', 'create', 'update', 'assign'],
            'viewings' => ['view', 'create', 'update'],
            'sales' => ['view', 'create', 'update'],
            'rentals' => ['view', 'create', 'update'],
            'maintenance' => ['view', 'create', 'update'],
            'finance' => ['view', 'create', 'update'],
            'reports' => ['view', 'export'],
            'content' => ['view', 'create', 'update', 'publish'],
            'settings' => ['view', 'update'],
        ];

        foreach ($groups as $module => $actions) {
            foreach ($actions as $action) {
                Permission::findOrCreate(
                    "{$module}.{$action}",
                    'web'
                );
            }
        }

        $roles = [
            'super_admin' => ['*'],

            'admin' => [
                'dashboard.*',
                'users.*',
                'roles.view',
                'properties.*',
                'owners.*',
                'customers.*',
                'leads.*',
                'viewings.*',
                'sales.*',
                'rentals.*',
                'maintenance.*',
                'finance.view',
                'reports.*',
                'content.*',
                'settings.view',
            ],

            'agent' => [
                'dashboard.view',
                'properties.view',
                'properties.create',
                'properties.update',
                'owners.view',
                'owners.create',
                'owners.update',
                'customers.view',
                'customers.create',
                'customers.update',
                'leads.view',
                'leads.create',
                'leads.update',
                'viewings.*',
                'sales.view',
                'sales.create',
                'sales.update',
                'rentals.view',
            ],

            'property_manager' => [
                'dashboard.view',
                'properties.view',
                'owners.view',
                'rentals.*',
                'maintenance.*',
                'reports.view',
            ],

            'accountant' => [
                'dashboard.view',
                'sales.view',
                'rentals.view',
                'finance.*',
                'reports.*',
            ],

            'content_officer' => [
                'dashboard.view',
                'properties.view',
                'content.*',
            ],

            'customer' => [],
        ];

        $allPermissions = Permission::where(
            'guard_name',
            'web'
        )->pluck('name')->all();

        foreach ($roles as $name => $patterns) {
            $role = Role::findOrCreate($name, 'web');

            $permissions = $name === 'super_admin'
                ? $allPermissions
                : array_values(array_filter(
                    $allPermissions,
                    function ($permission) use ($patterns) {
                        foreach ($patterns as $pattern) {
                            if (fnmatch($pattern, $permission)) {
                                return true;
                            }
                        }

                        return false;
                    }
                ));

            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
    }
}
