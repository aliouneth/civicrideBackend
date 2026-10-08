<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// Order matters: statuses + permissions are global reference data; tenant RBAC
// roles are derived from the permission matrix.
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RideStatusSeeder::class,
            RolePermissionSeeder::class,
            DemoTenantSeeder::class,
            PlatformAdminSeeder::class,
        ]);
    }
}