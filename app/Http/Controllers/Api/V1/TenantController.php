<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /** Platform signup: creates tenant + settings + RBAC + owner (no auth). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'alpha_dash', 'not_in:platform', 'unique:tenants,slug'],
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

    public function show()
    {
        return response()->json(app('tenant')->load('settings'));
    }

    /** Dispatch rules, timezone, service area — owner/admin only. */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'timezone' => ['sometimes', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'allow_driver_self_assignment' => ['sometimes', 'boolean'],
            'auto_suggest_nearest_driver' => ['sometimes', 'boolean'],
            'max_rides_per_driver_per_day' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'service_area_geojson' => ['nullable', 'array'],
        ]);

        $settings = app('tenant')->settings;
        $settings->update($data);

        return response()->json($settings->fresh());
    }
}
