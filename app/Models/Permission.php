<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Global catalogue (no tenant_id): names only. Assignment to roles is per-tenant.
class Permission extends Model
{
    public $timestamps = false;
    protected $fillable = ['name', 'description'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}
