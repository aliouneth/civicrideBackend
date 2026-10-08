<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use App\Http\Requests\UpdateTenantRequest;
use App\Http\Requests\PlatformTenantIndexRequest;

class PlatformTenantController extends Controller
{
    public function index(PlatformTenantIndexRequest $request)
    {
        $perPage = $request->input('per_page', 25);
        $search = $request->input('search');

        $query = Tenant::with('settings', 'users');

        if ($search) {
            $query->where('name', 'like', '%'.$search.'%')
                ->orWhere('slug', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%');
        }

        $tenants = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'data' => $tenants->items(),
            'per_page' => $perPage,
            'total' => $tenants->total(),
            'current_page' => $tenants->currentPage(),
            'last_page' => $tenants->lastPage(),
        ]);
    }

    public function show(Tenant $tenant)
    {
        return response()->json($tenant->load(['settings', 'users.role']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'alpha_dash', 'unique:tenants,slug'],
            'contact_email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'mileage_rate_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timezone' => ['sometimes', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'supervisor.name' => ['required', 'string', 'max:255'],
            'supervisor.email' => ['required', 'email'],
            'supervisor.password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $tenant = Tenant::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'contact_email' => $data['contact_email'],
            'phone' => $data['phone'] ?? null,
            'mileage_rate_cents' => $data['mileage_rate_cents'] ?? null,
        ]);

        $supervisor = $tenant->provisionDefaults([
            'name' => $data['supervisor']['name'],
            'email' => $data['supervisor']['email'],
            'password' => $data['supervisor']['password'],
            'timezone' => $data['timezone'] ?? 'UTC',
            'currency' => $data['currency'] ?? 'USD',
        ]);

        return response()->json(['tenant' => $tenant->fresh('settings'), 'supervisor' => $supervisor], 201);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $data = $request->validated();

        $tenant->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'contact_email' => $data['contact_email'],
            'phone' => $data['phone'] ?? null,
            'mileage_rate_cents' => $data['mileage_rate_cents'] ?? null,
        ]);

        if (isset($data['timezone']) || isset($data['currency'])) {
            $tenant->settings()->update(array_filter([
                'timezone' => $data['timezone'] ?? null,
                'currency' => $data['currency'] ?? null,
            ], fn ($v) => $v !== null));
        }

        if (! empty($data['supervisor'])) {
            // "supervisor" is the current role name; pre-rename tenants still
            // use "owner" for their agency head, so match both.
            $supervisor = $tenant->users()
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['supervisor', 'owner']))
                ->first();
            if ($supervisor) {
                $supervisor->update(array_filter([
                    'name' => $data['supervisor']['name'] ?? null,
                    'email' => $data['supervisor']['email'] ?? null,
                    'password' => $data['supervisor']['password'] ?? null,
                ], fn ($v) => $v !== null));
            }
        }

        return response()->json($tenant->fresh(['settings', 'users.role']));
    }

    public function toggleActive(Tenant $tenant)
    {
        $tenant->update(['is_active' => ! $tenant->is_active]);

        return response()->json($tenant->fresh());
    }
}