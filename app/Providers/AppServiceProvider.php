<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // MySQL 8 enables STRICT_TRANS_TABLES by default; no session overrides needed.

        // Brute-force protection on the credential endpoints
        // (HIPAA §164.312(d), SOC 2 CC6.1). Behind the Next.js BFF every
        // request arrives from the proxy's IP, so the per-email limit is the
        // precise control and the per-IP limit is only a generous backstop.
        RateLimiter::for('login', function (Request $request): array {
            $email = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(10)->by('login-email|'.$email.'|'.$request->ip()),
                Limit::perMinute(60)->by('login-ip|'.$request->ip()),
            ];
        });

        // Public self-registration: caps mass account creation from one source.
        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(10)->by('auth-ip|'.$request->ip());
        });
    }
}
