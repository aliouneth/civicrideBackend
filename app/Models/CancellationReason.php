<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CancellationReason extends Model
{
    protected $fillable = ['code', 'label', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** Active reasons ordered for presentation. */
    public static function activeList()
    {
        return static::where('is_active', true)->orderBy('sort_order')->orderBy('label')->get();
    }
}