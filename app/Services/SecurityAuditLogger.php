<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes the security audit trail (HIPAA §164.312(b), SOC 2 CC7.2).
 *
 * Deliberately NOT wrapped in try/catch: if an audit write fails the request
 * must fail loudly instead of completing an unlogged security-relevant action
 * (fail-closed). Never pass secrets — passwords, tokens, temporary passwords —
 * in $context.
 */
final class SecurityAuditLogger
{
    /**
     * Record one security-relevant event.
     *
     * @param  class-string<Model>|Model|null  $subject  row the action was performed on
     * @param  array<string, mixed>  $context  non-secret details (field names, roles, outcomes)
     */
    public static function record(
        string $event,
        ?Request $request = null,
        ?Model $actor = null,
        ?Model $subject = null,
        array $context = [],
    ): SecurityEvent {
        $request ??= request();

        return SecurityEvent::create([
            // Explicit tenant wins (actor or subject carry it); when both are
            // empty the BelongsToTenant hook falls back to the request tenant,
            // and a platform-mode request stays tenant-less (null).
            'tenant_id' => $actor?->getAttribute('tenant_id')
                ?? ($subject instanceof Tenant ? $subject->id : $subject?->getAttribute('tenant_id')),
            'actor_user_id' => $actor instanceof User ? $actor->id : null,
            'event' => $event,
            'subject_type' => $subject?->getTable(),
            'subject_id' => $subject?->getKey(),
            'ip_address' => $request?->ip(),
            'context' => $context ?: null,
        ]);
    }
}
