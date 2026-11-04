<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the append-only security audit trail.
 *
 * Written only through App\Services\SecurityAuditLogger; the application
 * never updates or deletes these rows. Insertion timestamp only — there is
 * no updated_at column because an audit record is immutable once written.
 */
class SecurityEvent extends Model
{
    use BelongsToTenant;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'actor_user_id', 'event', 'subject_type', 'subject_id',
        'ip_address', 'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /** The user who performed the action (null for anonymous events such as a failed login). */
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
