<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Usage: ->middleware('role:admin,scheduler'). Platform admins bypass.
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (! $user || (! $user->is_platform_admin && ! $user->isRole(...$roles))) {
            return response()->json(['message' => 'Forbidden for role.'], 403);
        }

        return $next($request);
    }
}
