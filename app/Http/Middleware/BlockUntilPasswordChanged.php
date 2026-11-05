<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Rejects every authenticated request from a user whose password a platform
 * administrator has forced them to reset (HIPAA §164.312(d), SOC 2 CC6.1),
 * except the handful needed to complete the change or sign out.
 *
 * Responds 423 Locked with code "password_change_required"; the Next.js BFF
 * forwards the status as-is and the client redirects to /password/change.
 */
class BlockUntilPasswordChanged
{
    /** Paths (without leading slash) a forced user may still call. */
    private const ALLOWED = [
        'api/v1/auth/me',
        'api/v1/auth/logout',
        'api/v1/auth/change-password',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('sanctum');

        if ($user instanceof User && $user->must_change_password && ! in_array($request->path(), self::ALLOWED, true)) {
            return response()->json([
                'message' => 'You must choose a new password before continuing.',
                'code' => 'password_change_required',
            ], 423);
        }

        return $next($request);
    }
}
