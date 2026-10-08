<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $slug = $request->header('X-Tenant') ?? $this->slugFromHost($request->getHost());

        // Empty string (X-Tenant: "") means platform-admin mode — skip tenant lookup.
        $tenant = $slug ? Tenant::where('slug', $slug)->where('is_active', true)->first() : null;

        if (! $tenant && $slug !== null && $slug !== "") {
            return response()->json(['message' => 'Unknown or inactive tenant. Send X-Tenant header or use the tenant subdomain.'], 404);
        }

        $user = $request->user();
        if ($user && $user->tenant_id !== null && ($tenant === null || $user->tenant_id !== $tenant->id)) {
            return response()->json(['message' => 'Token does not belong to this tenant.'], 403);
        }

        if ($tenant !== null) {
            app()->instance('tenant', $tenant);
        }

        return $next($request);
    }

    private function slugFromHost(string $host): ?string
    {
        // acme.civicride.com → acme. Bare domains / IPs have no tenant subdomain.
        $parts = explode('.', $host);

        return count($parts) > 2 ? $parts[0] : null;
    }
}