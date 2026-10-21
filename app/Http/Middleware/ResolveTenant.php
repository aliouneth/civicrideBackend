<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Binds the request tenant into the container (app('tenant')).
 *
 * Resolution order:
 *   1. X-Tenant: <slug>    explicit claim. Unknown or inactive slug -> 404.
 *   2. X-Tenant: platform  explicit "no tenant" (platform-admin mode): the
 *                           public host is not consulted and no tenant is
 *                           bound, so platform queries span every agency.
 *   3. X-Tenant absent     no claim: the public host decides - the host the
 *      or empty            browser used, forwarded by the trusted edge as
 *                           X-Tenant-Host, otherwise the request host. A label
 *                           that is not a tenant at all (infrastructure host
 *                           such as api/app/staging) is ignored - never a
 *                           404 - while a known but deactivated agency is.
 *   4. The token's tenant  a tenant user whose request carries no tenant
 *                           context at all: the token is authoritative.
 */
class ResolveTenant
{
    /** Header value that means "no tenant" (platform administration). */
    private const PLATFORM_MODE = 'platform';

    public function handle(Request $request, Closure $next)
    {
        $claim = $this->claimFromHeader($request);
        $tenant = null;

        if ($claim === self::PLATFORM_MODE) {
            // Explicit platform mode: no tenant, no host lookup.
            $tenant = null;
        } elseif ($claim !== null) {
            $tenant = Tenant::where('slug', $claim)->where('is_active', true)->first();

            if (! $tenant) {
                return $this->unknownTenantResponse();
            }
        } else {
            $hostSlug = $this->slugFromHost($this->publicHost($request));

            if ($hostSlug !== null) {
                $tenant = Tenant::where('slug', $hostSlug)->first();

                // A known but deactivated agency still fails loudly.
                if ($tenant && ! $tenant->is_active) {
                    return $this->unknownTenantResponse();
                }
            }

            if ($tenant === null) {
                $tenant = $this->tenantFromToken($request);
            }
        }

        $user = $this->authenticatedUser($request);

        if ($user && $user->tenant_id !== null && $tenant !== null && $user->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Token does not belong to this tenant.'], 403);
        }

        if ($tenant !== null) {
            app()->instance('tenant', $tenant);
        }

        return $next($request);
    }

    /**
     * The tenant claim carried by the X-Tenant header.
     *
     * @return string|null null when the header is absent or empty (no claim),
     *                     "platform" for platform-admin mode, otherwise the slug
     */
    private function claimFromHeader(Request $request): ?string
    {
        $value = $request->header('X-Tenant');

        if ($value === null) {
            return null;
        }

        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        return $value === self::PLATFORM_MODE ? self::PLATFORM_MODE : $value;
    }

    /** The tenant that owns the request token, when the request has no tenant context. */
    private function tenantFromToken(Request $request): ?Tenant
    {
        $user = $this->authenticatedUser($request);

        if (! $user || $user->tenant_id === null) {
            return null;
        }

        return $user->tenant;
    }

    /**
     * The authenticated user, if any.
     *
     * This middleware runs before auth:sanctum, so the default guard has not
     * been switched to "sanctum" yet: ask that guard directly, otherwise a
     * bearer-token request looks anonymous here.
     */
    private function authenticatedUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User ? $user : null;
    }

    /**
     * The host the browser used. The trusted edge (Next.js BFF) forwards it as
     * X-Tenant-Host because the API server only sees its own hostname.
     */
    private function publicHost(Request $request): string
    {
        $forwarded = $request->header('X-Tenant-Host');

        if (is_string($forwarded) && trim($forwarded) !== '') {
            return trim($forwarded);
        }

        return (string) $request->getHost();
    }

    /**
     * Tenant slug carried by a host, or null when the host carries none.
     *
     * acme.civicride.com -> acme. Bare domains, IP literals and infrastructure
     * subdomains carry no tenant.
     */
    private function slugFromHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        // IP literals (127.0.0.1, ::1, 10.0.0.5) have dot-separated parts but
        // no tenant subdomain — without this guard "127" becomes the slug and
        // every header-less request (e.g. the login form, which sends no
        // X-Tenant) 404s with "Unknown or inactive tenant".
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        $baseDomain = strtolower(trim((string) config('tenancy.base_domain')));

        if ($baseDomain !== '' && str_ends_with($host, '.'.$baseDomain)) {
            // acme.civicride.com -> acme
            $host = substr($host, 0, -strlen('.'.$baseDomain));

            // Deeper subdomains (a.b.civicride.com) are never tenant hosts.
            if (str_contains($host, '.')) {
                return null;
            }
        } elseif (count(explode('.', $host)) < 3) {
            // Bare domain (civicride.com) or plain host (localhost): no
            // tenant subdomain to read.
            return null;
        }

        $slug = explode('.', $host)[0];

        if ($slug === '' || in_array($slug, (array) config('tenancy.reserved_subdomains'), true)) {
            return null;
        }

        return $slug;
    }

    private function unknownTenantResponse()
    {
        return response()->json([
            'message' => 'Unknown or inactive tenant. Send X-Tenant header or use the tenant subdomain.',
        ], 404);
    }
}
