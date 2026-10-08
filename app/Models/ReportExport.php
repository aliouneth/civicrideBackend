<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'requested_by', 'type', 'filters',
        'status', 'file_path', 'error', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['filters' => 'array', 'finished_at' => 'datetime'];
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
