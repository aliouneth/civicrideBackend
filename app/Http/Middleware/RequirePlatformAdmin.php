<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;

class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! $user->is_platform_admin) {
            return response()->json(['message' => 'Forbidden: platform admin access required.'], 403);
        }

        return $next($request);
    }
}