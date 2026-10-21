<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Agency identity — the fields a tenant administrator may correct.
 *
 * `slug` is deliberately absent: it is the tenancy key used by the X-Tenant
 * header and *.civicride.com subdomains, so renaming it would silently break
 * every existing login. Operational knobs live in UpdateTenantSettingsRequest.
 */
class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canDo('tenants.settings');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', 'alpha_dash', 'not_in:platform', \Illuminate\Validation\Rule::unique('tenants', 'slug')->ignore($this->route('tenant')?->id)],
            'contact_email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'mileage_rate_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timezone' => ['sometimes', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'supervisor.name' => ['sometimes', 'string', 'max:255'],
            'supervisor.email' => ['sometimes', 'email', 'max:255'],
            'supervisor.password' => ['sometimes', 'string', 'min:10', 'confirmed'],
        ];
    }
}