<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use RuntimeException;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $name = 'Gracious Administrator';
        $email = 'admin@graciousinvestment.test';
        $password = 'Gracious@2026Admin!';

        if (!Role::where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->exists()) {
            throw new RuntimeException(
                'Run RolePermissionSeeder first.'
            );
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'status' => 'active',
            ]
        );

        $user->assignRole('super_admin');

        $this->command?->info(
            'Super Admin created: ' . $user->email
        );
    }
}
