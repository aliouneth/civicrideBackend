<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'description'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    public function grants(string $permission): bool
    {
        return $this->permissions->contains('name', $permission);
    }
}
