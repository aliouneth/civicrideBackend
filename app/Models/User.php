<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use BelongsToTenant, HasApiTokens;

    protected $fillable = [
        'tenant_id', 'role_id', 'name', 'email', 'phone',
        'password', 'is_platform_admin', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function driverProfile()
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function isRole(string ...$names): bool
    {
        return in_array($this->role->name, $names, true);
    }

    public function canDo(string $permission): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        return $this->role->grants($permission);
    }
}
