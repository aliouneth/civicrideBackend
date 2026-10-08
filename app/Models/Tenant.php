<?php

namespace App\Models;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Tenant extends Model
{
    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = ['name', 'slug', 'contact_email', 'phone', 'is_active', 'mileage_rate_cents'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function settings()
    {
        return $this->hasOne(TenantSetting::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Provision a new tenant: settings row, default RBAC roles, supervisor user.
     * Called explicitly from TenantController — never hidden in model events.
     */
    public function provisionDefaults(array $supervisor): User
    {
        return DB::transaction(function () use ($supervisor) {
            $this->settings()->create([
                'timezone' => $supervisor['timezone'] ?? 'UTC',
                'currency' => $supervisor['currency'] ?? 'USD',
            ]);

            RolePermissionSeeder::seedTenantRoles($this);

            $supervisorRole = $this->roles()->where('name', 'supervisor')->firstOrFail();

            return $this->users()->create([
                'role_id' => $supervisorRole->id,
                'name' => $supervisor['name'],
                'email' => $supervisor['email'],
                'password' => $supervisor['password'], // hashed by User cast
            ]);
        });
    }
}
