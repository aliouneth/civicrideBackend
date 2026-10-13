<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        abort_if(! $request->user()->canDo('users.manage'), 403);

        // Eager load role (avoids N+1); cursor pagination (stable under inserts).
        $users = User::with('role')->orderBy('id')->cursorPaginate(25);

        return response()->json($users);
    }

    /**
     * Invite scheduler/admin/driver: creates the user with a one-time temporary
     * password that is returned to the inviter (no email infrastructure yet).
     */
    public function invite(Request $request)
    {
        abort_if(! $request->user()->canDo('users.invite'), 403);

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

        $temporaryPassword = Str::random(16);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $temporaryPassword, // rotated at first login (phase 2: email invite link)
        ]);

        if ($data['role'] === 'driver') {
            $user->driverProfile()->create(['license_number' => 'PENDING']);
        }

        return response()->json([
            'user' => $user->fresh('role'),
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function toggleActive(Request $request, User $user)
    {
        abort_if(! $request->user()->canDo('users.manage'), 403);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return response()->json($user->fresh('role'));
    }
}
