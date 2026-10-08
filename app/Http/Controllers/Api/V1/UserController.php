<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index()
    {
        // Eager load role (avoids N+1); cursor pagination (stable under inserts).
        $users = User::with('role')->orderBy('id')->cursorPaginate(25);

        return response()->json($users);
    }

    /** Invite scheduler/admin/driver: creates the user with a temp password. */
    public function invite(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', 'string', 'in:admin,scheduler,driver'],
        ]);

        $tenant = app('tenant');
        if (User::where('tenant_id', $tenant->id)->where('email', $data['email'])->exists()) {
            return response()->json(['message' => 'Email already invited in this agency.'], 422);
        }

        $role = Role::where('tenant_id', $tenant->id)->where('name', $data['role'])->firstOrFail();

        $user = User::create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Str::random(24), // rotated at first login (phase 2: email invite link)
        ]);

        if ($data['role'] === 'driver') {
            $user->driverProfile()->create(['license_number' => 'PENDING']);
        }

        return response()->json($user->fresh('role'), 201);
    }

    public function toggleActive(User $user)
    {
        $user->update(['is_active' => ! $user->is_active]);

        return response()->json($user->fresh('role'));
    }
}
