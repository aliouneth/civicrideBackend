<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
        ]);

        $user = $user->fresh(['role', 'driverProfile']);
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
        return response()->json([
            'user' => $user,
            'token' => $this->issueToken($user),
            'tenant' => $tenantSlug,
            'onboarding_status' => $onboardingStatus,
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
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
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