<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Enforces single-database multi-tenancy: every query on a tenant model is
 * automatically scoped to the request tenant, and new rows inherit it.
 * No tenant bound (console/seeders) → scope is skipped, never guessed.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            if (self::currentTenant()) {
                $query->where($query->getModel()->getTable().'.tenant_id', self::currentTenant()->id);
            }
        });

        static::creating(function (Model $model): void {
            if (empty($model->tenant_id) && self::currentTenant()) {
                $model->tenant_id = self::currentTenant()->id;
            }
        });
    }

    public static function currentTenant(): ?Tenant
    {
        return app()->bound('tenant') ? app('tenant') : null;
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
