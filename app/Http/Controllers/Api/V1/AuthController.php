<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

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

        $user->driverProfile()->create(['license_number' => 'PENDING']);

        return response()->json(['user' => $user->fresh('role'), 'token' => $this->issueToken($user)], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $tenant = app()->bound('tenant') ? app('tenant') : null;
        $user = $tenant ? User::where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->where('is_active', true)
            ->first() : null;

        if (! $user) {
            // Platform admin login — tenant_id is NULL for platform admins
            $user = User::where('email', $data['email'])
                ->where('is_platform_admin', true)
                ->where('is_active', true)
                ->first();

            if (! $user || ! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
            }
        } else {
            // Regular tenant user login
            if (! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => ['Invalid credentials for this agency.']]);
            }
        }

        return response()->json([
            'user' => $user->load('role'),
            'token' => $this->issueToken($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('role', 'driverProfile'));
    }

    /** Token ability = role name, so stateless gates can read it without a DB hit. */
    private function issueToken(User $user): string
    {
        return $user->createToken('api', [$user->role->name])->plainTextToken;
    }
}
