<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\SecurityAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Self-registration creates a driver in the resolved tenant. */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'license_number' => ['required', 'string', 'max:64'],
            'license_expiry' => ['required', 'date', 'after:today'],
            'vehicle_make' => ['required', 'string', 'max:64'],
            'vehicle_model' => ['required', 'string', 'max:64'],
            'vehicle_year' => ['required', 'integer', 'min:1990', 'max:' . (now()->year + 1)],
            'vehicle_color' => ['required', 'string', 'max:32'],
            'vehicle_plate' => ['required', 'string', 'max:16'],
            'home_address' => ['nullable', 'string', 'max:255'],
            'home_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'home_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers(), 'confirmed'],
            'turnstile_token' => ['required', 'string'],
        ]);

        $this->verifyTurnstile($request->input('turnstile_token'));

        $tenant = app()->bound('tenant') ? app('tenant') : null;
        abort_if($tenant === null, 400, 'Tenant context required for registration.');
        if (User::where('tenant_id', $tenant->id)->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => ['Email already registered in this agency.']]);
        }

        $driverRole = Role::where('tenant_id', $tenant->id)->where('name', 'driver')->firstOrFail();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'role_id' => $driverRole->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);

        $user->driverProfile()->create([
            'license_number' => $data['license_number'],
            'license_expiry' => $data['license_expiry'],
            'vehicle_make' => $data['vehicle_make'],
            'vehicle_model' => $data['vehicle_model'],
            'vehicle_year' => $data['vehicle_year'],
            'vehicle_color' => $data['vehicle_color'],
            'vehicle_plate' => $data['vehicle_plate'],
            'home_address' => $data['home_address'] ?? null,
            'home_lat' => $data['home_lat'] ?? null,
            'home_lng' => $data['home_lng'] ?? null,
        ]);

        $user = $user->fresh(['role', 'driverProfile']);
        SecurityAuditLogger::record('auth.register', $request, actor: $user, subject: $user);

        return response()->json([
            'user' => $user,
            'token' => $this->issueToken($user),
            'onboarding_status' => $user->driverProfile?->onboarding_status ?? 'applied',
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            SecurityAuditLogger::record(
                'auth.login_failed',
                $request,
                subject: $user,
                context: ['email' => $data['email']],
            );

            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }

        // Attach the user's tenant to the app context for the session.
        // Drivers/users have a tenant; platform admins have no tenant.
        if ($user->driverProfile) {
            app()->instance('tenant', $user->driverProfile->tenant);
        } elseif ($user->is_platform_admin) {
            app()->instance('tenant', null);
        }

        $tenantSlug = null;
        if ($user->driverProfile?->tenant) {
            $tenantSlug = $user->driverProfile->tenant->slug;
        } elseif ($user->is_platform_admin) {
            $tenantSlug = 'platform';
        } elseif ($user->tenant_id) {
            $tenantSlug = $user->tenant?->slug;
        }

        $user = $user->load(['role', 'tenant', 'driverProfile']);
        $onboardingStatus = $user->driverProfile?->onboarding_status ?? 'applied';

        SecurityAuditLogger::record('auth.login', $request, actor: $user, context: ['tenant' => $tenantSlug]);

        return response()->json([
            'user' => $user,
            'token' => $this->issueToken($user),
            'tenant' => $tenantSlug,
            'onboarding_status' => $onboardingStatus,
            // True when a platform administrator forced a password reset: the
            // client must route to /password/change instead of the app home.
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    public function session(Request $request)
    {
        return response()->json([
            'token' => $request->user()->token ?? '',
            'tenant' => app('tenant') ?? '',
        ]);
    }

    public function logout(Request $request)
    {
        SecurityAuditLogger::record('auth.logout', $request, actor: $request->user());

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Change the caller's own password. Clears must_change_password, which is
     * how a forced reset (set by a platform administrator) is completed.
     * The current password is always required, so a stolen session alone is
     * not enough to take over the account.
     */
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers(), 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $wasForced = (bool) $user->must_change_password;

        $user->update([
            'password' => $data['password'],
            'must_change_password' => false,
        ]);

        SecurityAuditLogger::record('auth.password_changed', $request, actor: $user, context: ['forced_reset' => $wasForced]);

        return response()->json(['message' => 'Password updated.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load(['role', 'tenant', 'driverProfile.availability', 'driverProfile.documents']));
    }

    /** Token ability = role name, so stateless gates can read it without a DB hit. */
    private function issueToken(User $user): string
    {
        return $user->createToken('api', [$user->role->name])->plainTextToken;
    }

    private function verifyTurnstile(string $token): void
    {
        $secret = config('services.turnstile.secret');
        if (! $secret) {
            return; // Skip verification if not configured
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secret,
            'response' => $token,
        ]);

        $result = $response->json();
        if (! ($result['success'] ?? false)) {
            throw ValidationException::withMessages([
                'turnstile_token' => ['Security check failed. Please try again.'],
            ]);
        }
    }
}