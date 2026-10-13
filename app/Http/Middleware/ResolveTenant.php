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
        // IP literals (127.0.0.1, ::1, …) have dot-separated parts but no
        // tenant subdomain — without this guard "127" becomes the slug and
        // every header-less request (e.g. the login form, which sends no
        // X-Tenant) 404s with "Unknown or inactive tenant".
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        // acme.civicride.com → acme. Bare domains / IPs have no tenant subdomain.
        // Reserved infrastructure subdomains are never tenant slugs — without
        // this, api.civicride.com resolves slug "api" and header-less login
        // 404s in production while working on localhost.
        $parts = explode('.', $host);
        if (count($parts) <= 2) {
            return null;
        }

        $subdomain = strtolower($parts[0]);
        if (in_array($subdomain, ['api', 'www', 'app'], true)) {
            return null;
        }

        return $parts[0];
    }
}