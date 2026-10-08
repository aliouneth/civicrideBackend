<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Find or create the platform admin role (roles are per-tenant; attach to first tenant)
        $tenant = \App\Models\Tenant::first();
        $platformAdminRole = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'platform-admin'],
            ['description' => 'Platform Administrator']
        );

        // Find or create the admin role (if not exists) for permissions
        $adminRole = Role::where('name', 'admin')->first();

        // Create the platform super administrator user
        // This user has NULL tenant_id, marking them as a platform-wide admin
        $platformAdmin = User::firstOrCreate(
            ['email' => 'superadmin@civicride.test'],
            [
                'name' => 'Platform Super Admin',
                'password' => Hash::make('password123'), // change on first login
                'is_platform_admin' => true,
                'is_active' => true,
                'role_id' => $platformAdminRole->id,
            ]
        );

        // Output confirmation
        $this->command->info('Platform super administrator created.');
        $this->command->info('Email: superadmin@civicride.test');
        $this->command->info('Password: password123 (change on first login)');
    }
}