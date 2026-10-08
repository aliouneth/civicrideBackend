<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

// Default RBAC matrix, copied per tenant on tenant creation (see Tenant model).
class RolePermissionSeeder extends Seeder
{
    /** Permission => roles that hold it. */
    public const MATRIX = [
        'tenants.settings'   => ['supervisor', 'admin'],
        'users.invite'       => ['supervisor', 'admin'],
        'users.manage'       => ['supervisor', 'admin'],
        'drivers.approve'    => ['supervisor', 'admin'],
        'drivers.view'       => ['supervisor', 'admin', 'scheduler'],
        'rides.create'       => ['supervisor', 'admin', 'scheduler'],
        'rides.update'       => ['supervisor', 'admin', 'scheduler'], // correct details while not yet in motion
        'rides.view'         => ['supervisor', 'admin', 'scheduler', 'driver'],
        'rides.assign'       => ['supervisor', 'admin', 'scheduler'],
        'rides.self_assign'  => ['driver'], // additionally gated by tenant setting
        'rides.execute'      => ['driver'],
        'rides.cancel'       => ['supervisor', 'admin', 'scheduler'],
        'rides.series'       => ['supervisor', 'admin', 'scheduler'], // browse/rename/shift a recurring series
        'messages.send'      => ['supervisor', 'admin', 'scheduler', 'driver'],
        'reports.view'       => ['supervisor', 'admin', 'scheduler'],
        'reports.export'     => ['supervisor', 'admin'],
    ];

    public function run(): void
    {
        foreach (array_keys(self::MATRIX) as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }

        Tenant::all()->each(fn (Tenant $tenant) => $this->seedTenantRoles($tenant));
    }

    public static function seedTenantRoles(Tenant $tenant): void
    {
        $permissions = Permission::all()->keyBy('name');

        foreach (['supervisor', 'admin', 'scheduler', 'driver'] as $roleName) {
            $role = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $roleName],
                ['description' => ucfirst($roleName).' role']
            );

            $allowed = array_keys(array_filter(
                self::MATRIX,
                fn (array $roles) => in_array($roleName, $roles, true)
            ));

            // filter() by name, NOT ->only($allowed): Eloquent\Collection::only()
            // matches on model primary keys, so passing permission *names* to it
            // returns an empty set — and sync([]) would then detach everything.
            $role->permissions()->sync(
                $permissions->filter(fn (Permission $p) => in_array($p->name, $allowed, true))->modelKeys()
            );
        }
    }
}
